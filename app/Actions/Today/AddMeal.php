<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\MealAddInput;
use App\Domain\Placement\ClockTime;
use App\Domain\Retarget\Retargeting;
use App\Models\User;
use DomainException;

/**
 * SCH-031: an extra meal at a chosen time, under the client's id (CAP-019). Every meal keeps its time and the day's
 * total holds; the meals not checked in, locked or hand-edited (SCH-055) and the new one share what the fixed ones
 * leave.
 */
final class AddMeal
{
    /** @return array{toast: string, dates: list<string>, week: list<string>} the toast, the day (Y-m-d) and its plan week */
    public function __invoke(User $user, MealAddInput $input): array
    {
        $day = RetargetableDay::load($user, $input->date);
        $time = ClockTime::fromHhmm($input->time) ?? throw new DomainException('Pick a time.');
        // DayTarget stores meal ids lowercased; a client may send a ULID in capitals.
        $result = Retargeting::addMeal($day->day, $time, $day->context, strtolower($input->id));
        $day->save($result->days[0]);

        return ['toast' => $result->message, 'dates' => [$day->date], 'week' => $day->weekDates()];
    }
}
