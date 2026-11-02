<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\MissedSessionResolveInput;
use App\Domain\Retarget\MissedDayOption;
use App\Domain\Retarget\MissedDayOption\Kind;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetRefusal;
use App\Domain\Retarget\RetargetRefused;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\DayPlans;
use App\Support\Today\RetargetDays;
use App\Support\Today\SessionWeek;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCH-057: the answer to the missed-day prompt for a session of this plan week whose day has passed with nothing
 * logged. "Do it today" and "Do it on the next rest day" move the session there at its clock time and re-target that
 * day (SCH-050, SCH-055, SCH-056); the missed day is past, so its targets stay. "Skip it" saves the session skipped and
 * changes no targets. The server offers the choices itself, so a choice it would not offer is refused.
 */
final class ResolveMissedSession
{
    public const NOT_MISSED = 'That session isn\'t waiting for an answer: it is done, skipped, not yet due, or not in this plan week.';

    /** @return array{toast: string, dates: list<string>, week: list<string>} the toast, the days Today shows differently and the days of their plan week (Y-m-d) */
    public function __invoke(User $user, MissedSessionResolveInput $input): array
    {
        $snapshot = TrainSnapshot::load($user, $input->mesocycle_id);
        $mesocycle = $snapshot->mesocycle($input->mesocycle_id);
        $week = SessionWeek::load($snapshot, $mesocycle);
        $session = $week->session($input->week, $input->day);
        $stored = $snapshot->sessions->first(fn (WorkoutSession $row) => $row->mesocycle_id === $mesocycle->id
            && $row->week === $input->week && $row->day === $input->day);
        if ($session === null || $session->date >= $snapshot->today || ($stored !== null && SessionWeek::isLogged($stored))) {
            throw new DomainException(self::NOT_MISSED);
        }

        $option = $this->offered($input, Retargeting::missedDayOptions($session->date, $snapshot->today, $week->planWeek, $week->trainingDates()), $snapshot->today);
        $weekDates = array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $week->planWeek->days());
        if ($option->kind === Kind::Skip) {
            PlannedSession::saveRow($user, $stored, $mesocycle->id, $input->week, $input->day, $session->date, $session->time, skipped: true);

            return ['toast' => 'Session skipped', 'dates' => [$session->date->format('Y-m-d')], 'week' => $weekDates];
        }

        $destination = $option->date ?? $snapshot->today;
        $date = $destination->format('Y-m-d');
        $plans = DayPlans::load($user, [$date]);
        $plan = $plans->plan($date) ?? throw new DomainException(RetargetDays::CANNOT);
        $context = RetargetDays::context($user, $destination, $week->result->isDeload, $week->row);
        $workout = $week->workout($session);
        try {
            $result = Retargeting::move(
                RetargetDays::emptyDay($session->date, $workout),
                RetargetDays::day($plan, null, RetargetDays::activity($user, $date), $context),
                $session->time->start,
                $week->planWeek,
                $context,
                fn (): string => (string) Str::uuid7(),
            );
        } catch (RetargetRefused $refused) {
            throw new DomainException(self::refusal($refused->refusal));
        }
        // The day and the session's row are saved together or not at all.
        DB::transaction(function () use ($plans, $date, $result, $user, $stored, $input, $session, $destination, $mesocycle) {
            RetargetDays::save($plans->row($date), $result->days[1]);
            PlannedSession::saveRow($user, $stored, $mesocycle->id, $input->week, $input->day, $destination, $session->time);
        });

        return ['toast' => $result->message, 'dates' => [$session->date->format('Y-m-d'), $date], 'week' => $weekDates];
    }

    /**
     * The option the answer names, on the day the prompt named: a late answer (an outbox replay, a prompt left open
     * past midnight) is refused rather than moved to another day.
     *
     * @param  list<MissedDayOption>  $options
     */
    private function offered(MissedSessionResolveInput $input, array $options, DateTimeImmutable $today): MissedDayOption
    {
        $kind = match ($input->choice) {
            'do_it_today' => Kind::DoItToday,
            'next_rest_day' => Kind::NextRestDay,
            default => Kind::Skip,
        };
        $option = array_find($options, fn (MissedDayOption $option) => $option->kind === $kind);
        $day = $option === null ? null : ($option->kind === Kind::DoItToday ? $today : $option->date);
        if ($option === null || ($day !== null && $input->date !== null && $day->format('Y-m-d') !== $input->date)) {
            throw new DomainException(match (true) {
                $option !== null => 'That day has changed. Open the prompt again.',
                $kind === Kind::DoItToday => 'Today is a training day, so this session can\'t move to it.',
                default => 'No rest day is left this plan week, so this session can only be skipped.',
            });
        }

        return $option;
    }

    /** The re-target rule's refusal, as the user reads it. Nothing is saved. */
    public static function refusal(RetargetRefusal $refusal): string
    {
        return match ($refusal) {
            RetargetRefusal::NoSession => 'There is no workout on that day to change. Nothing was changed.',
            RetargetRefusal::DayHasSession => 'That day already has a workout. Pick another day.',
            RetargetRefusal::OutsidePlanWeek => 'A workout can only move within its plan week. Pick another day.',
            RetargetRefusal::MealsDoNotFit => 'Your meals can\'t fit around the workout at that time. Nothing was changed.',
        };
    }
}
