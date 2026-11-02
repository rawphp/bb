<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\WorkoutTimeSetInput;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetRefused;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SCH-050, SCH-056: a planned session starts at another time on its day. Its row is saved from that start for its
 * length, and the day's unchecked meals are placed again around it, together or not at all.
 */
final class SetWorkoutTime
{
    /** @return array{toast: string, dates: list<string>, week: list<string>} the toast, the day (Y-m-d) and its plan week */
    public function __invoke(User $user, WorkoutTimeSetInput $input): array
    {
        $session = PlannedSession::movable($user, $input->mesocycle_id, $input->week, $input->day);
        $start = ClockTime::fromHhmm($input->time) ?? throw new DomainException('Pick a time.');
        $day = RetargetableDay::load($user, $session->item->date->format('Y-m-d'), $session->snapshot);
        try {
            $result = Retargeting::retime($day->day, $start, $day->context, RetargetableDay::newId(...));
        } catch (RetargetRefused $refused) {
            throw RetargetableDay::refused($refused);
        }
        if ($result === null) {
            return ['toast' => "Your workout is already at {$start->shortText()}", 'dates' => [], 'week' => []];
        }

        DB::transaction(function () use ($day, $result, $user, $session, $input, $start) {
            $day->save($result->days[0]);
            PlannedSession::saveRow($user, $session->row, $session->item->mesocycle->id, $input->week, $input->day, $session->item->date,
                new WorkoutTime($start, $session->scheduled->time->lengthMinutes));
        });

        return ['toast' => $result->message, 'dates' => [$day->date], 'week' => $day->weekDates()];
    }
}
