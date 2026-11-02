<?php

namespace App\Actions\Workouts;

use App\Domain\Model\MuscleGroup;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetRefused;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\DayPlans;
use App\Support\Today\RetargetDays;
use App\Support\Today\SessionWeek;
use App\Support\Workouts\NextWeekSets;
use App\Support\Workouts\WorkoutSnapshot;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WO-010, WO-040: finishes a started session once every set is logged or skipped and its feedback is answered. It
 * records the end time, works out next week's sets for its day from the feedback (PROG-010 to PROG-019, PROG-050,
 * PROG-051), and, when the session ran more than 20 minutes off its planned length, re-targets today's meals still to
 * come (SCH-054). A day with no targets (training only) or that is not today keeps its meals. All of it is saved
 * together or not at all.
 */
final class FinishWorkout
{
    public const NOT_STARTED = 'Start this workout before finishing it.';

    public const FINISHED = 'This workout is already finished.';

    public const SETS_LEFT = 'Log or skip every set before finishing.';

    /**
     * @param  DateTimeInterface  $at  when the user finished
     * @return array{toast: ?string, session: WorkoutSession, dates: list<string>, week: list<string>} the toast, the session, the days whose meals changed and their plan week (Y-m-d)
     */
    public function __invoke(User $user, string $workoutId, DateTimeInterface $at): array
    {
        $snapshot = WorkoutSnapshot::load($user, $workoutId);
        $session = $snapshot->session;
        if ($session->is_skipped || ! $snapshot->isStarted()) {
            throw new DomainException(self::NOT_STARTED);
        }
        if ($snapshot->isFinished()) {
            throw new DomainException(self::FINISHED);
        }
        if ($snapshot->sets->isEmpty() || $snapshot->sessionExercises() === [] || ! array_all($snapshot->sessionExercises(), fn ($exercise) => $exercise->isDone)) {
            throw new DomainException(self::SETS_LEFT);
        }
        $pending = $snapshot->sheets()->pending();
        if ($pending !== []) {
            throw new DomainException('Your feedback is required for '.self::list($pending).'.');
        }

        $start = DateTimeImmutable::createFromInterface($session->actual_start_at);
        $end = max($start, DateTimeImmutable::createFromInterface($at));

        return DB::transaction(function () use ($user, $snapshot, $start, $end): array {
            $snapshot->session->actual_end_at = $end;
            $snapshot->session->save();
            $sets = NextWeekSets::save($snapshot);
            $meals = $this->retarget($user, $snapshot, $start, $end);
            $lines = array_values(array_filter([$sets, $meals['message']]));

            return [
                'toast' => $lines === [] ? null : implode("\n", $lines),
                'session' => $snapshot->session,
                'dates' => $meals['dates'],
                'week' => $meals['week'],
            ];
        });
    }

    /**
     * SCH-054: today's meals still to come follow the session's actual length, when it ran more than 20 minutes off
     * plan. Nothing changes for a session outside today's plan week or on another day, or a day the rule cannot
     * re-target (no targets, no eating window, meals that do not fit).
     *
     * @return array{message: ?string, dates: list<string>, week: list<string>}
     */
    private function retarget(User $user, WorkoutSnapshot $snapshot, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $none = ['message' => null, 'dates' => [], 'week' => []];
        $session = $snapshot->session;
        if ($snapshot->train === null || $snapshot->mesocycle === null || ! $snapshot->mesocycle->isSaved()) {
            return $none;
        }
        try {
            $week = SessionWeek::load($snapshot->train, $snapshot->mesocycle);
            $scheduled = $week->session($session->week, $session->day);
            if ($scheduled === null || $scheduled->date != $snapshot->train->today) {
                return $none;
            }
            $date = $scheduled->date->format('Y-m-d');
            $plans = DayPlans::load($user, [$date]);
            $plan = $plans->plan($date);
            $row = $plans->row($date);
            if ($plan === null || $row === null) {
                return $none;
            }
            $context = RetargetDays::context($user, $scheduled->date, $week->result->isDeload, $week->row);
            $result = Retargeting::ranFor(
                RetargetDays::day($plan, $week->workout($scheduled), RetargetDays::activity($user, $date), $context),
                intdiv($end->getTimestamp() - $start->getTimestamp(), 60),
                $context,
                fn (): string => (string) Str::uuid7(),
            );
        } catch (DomainException|RetargetRefused) {
            return $none;
        }
        if ($result === null) {
            return $none;
        }
        RetargetDays::save($row, $result->days[0]);

        return [
            'message' => $result->message,
            'dates' => [$date],
            'week' => array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $week->planWeek->days()),
        ];
    }

    /** @param  list<MuscleGroup>  $muscles */
    private static function list(array $muscles): string
    {
        $names = array_map(fn (MuscleGroup $muscle) => strtolower($muscle->title()), $muscles);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }
}
