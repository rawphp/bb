<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\WorkoutMoveInput;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetRefusal;
use App\Domain\Retarget\RetargetRefused;
use App\Models\User;
use App\Support\Today\DayPlans;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SCH-032, SCH-038: moves a planned session to another day of its plan week at its clock time, from Move to another
 * day or Add workout. Only a day TodaySnapshot::moveDays() offers, holding no session, is accepted. Both days are
 * re-targeted and saved with the session's row, or nothing is.
 */
final class MoveWorkout
{
    public const SAME_DAY = 'That workout is already on that day.';

    /** @return array{toast: string, dates: list<string>, week: list<string>} the toast, the two days (Y-m-d) and their plan week */
    public function __invoke(User $user, WorkoutMoveInput $input): array
    {
        $session = PlannedSession::movable($user, $input->mesocycle_id, $input->week, $input->day);
        $from = $session->item->date;
        $to = DayPlans::day($input->date);
        $offered = array_find($session->snapshot->moveDays($from), fn (array $day) => $day['date'] == $to);
        if ($offered === null || $offered['taken']) {
            throw new DomainException(match (true) {
                $offered !== null => ResolveMissedSession::refusal(RetargetRefusal::DayHasSession),
                $to == $from => self::SAME_DAY,
                $to < $session->snapshot->today() => RetargetableDay::PAST,
                default => ResolveMissedSession::refusal(RetargetRefusal::OutsidePlanWeek),
            });
        }

        $source = RetargetableDay::load($user, $from->format('Y-m-d'), $session->snapshot);
        $destination = RetargetableDay::load($user, $input->date, $session->snapshot);
        $week = $source->planWeek() ?? throw new DomainException(PlannedSession::NOT_FOUND);
        try {
            $result = Retargeting::move($source->day, $destination->day, $session->scheduled->time->start, $week, $destination->context, RetargetableDay::newId(...));
        } catch (RetargetRefused $refused) {
            throw RetargetableDay::refused($refused);
        }
        // Both days and the session's row are saved together or not at all.
        DB::transaction(function () use ($source, $destination, $result, $user, $session, $input, $to) {
            $source->save($result->days[0]);
            $destination->save($result->days[1]);
            PlannedSession::saveRow($user, $session->row, $input->mesocycle_id, $input->week, $input->day, $to, $session->scheduled->time);
        });

        return ['toast' => $result->message, 'dates' => [$source->date, $destination->date], 'week' => $source->weekDates()];
    }
}
