<?php

namespace App\Support\Workout;

use App\Models\Mesocycle;
use Closure;

/**
 * A change to one day of a mesocycle, written where that day runs (MESO-045, EX-008, WO-031): the rotation, and each
 * week stored on its own (SCH-039) from `$fromWeek` on, so the weeks before keep what they ran. Only the changed day is
 * written, by position, with the ids it reads as: every other day, and the day's stored label, stay as stored.
 */
final class MesocycleDay
{
    /**
     * @param  int  $fromWeek  the first week (from 1) that runs the change
     * @param  Closure(list<array{id: string, exercise_id: ?int, muscle_group: string, sets: int, carried: ?array{weight_kg: float, reps: int}}>): list<array<string, mixed>>  $slots
     * @return bool whether anything changed; the mesocycle is saved when it did
     */
    public static function slots(Mesocycle $mesocycle, string $dayId, int $fromWeek, Closure $slots): bool
    {
        return self::change($mesocycle, $dayId, $fromWeek, function (array $stored, array $read) use ($slots) {
            $changed = $slots($read['slots']);
            if ($changed === $read['slots']) {
                return $stored;
            }
            $stored['id'] = $read['id'];
            $stored['slots'] = $changed;

            return $stored;
        });
    }

    /** WO-031: the day's name in every week of the mesocycle. A week where it already reads so is left as stored. */
    public static function label(Mesocycle $mesocycle, string $dayId, string $label): bool
    {
        return self::change($mesocycle, $dayId, 1, function (array $stored, array $read) use ($label) {
            if ($read['label'] === $label) {
                return $stored;
            }
            $stored['id'] = $read['id'];
            $stored['label'] = $label;

            return $stored;
        });
    }

    /** @param  Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $edit  the stored day and the day as it reads → the stored day changed */
    private static function change(Mesocycle $mesocycle, string $dayId, int $fromWeek, Closure $edit): bool
    {
        $days = self::edited(array_values($mesocycle->days ?? []), $mesocycle->dayRows(), $dayId, $edit);
        $earlier = array_values($mesocycle->earlier_weeks ?? []);
        foreach ($mesocycle->earlierWeekRows() as $week => $rows) {
            if ($week + 1 >= $fromWeek) {
                $earlier[$week] = self::edited(array_values($earlier[$week] ?? []), $rows, $dayId, $edit);
            }
        }
        if ($days === array_values($mesocycle->days ?? []) && $earlier === array_values($mesocycle->earlier_weeks ?? [])) {
            return false;
        }
        $mesocycle->update(['days' => $days, 'earlier_weeks' => $mesocycle->earlier_weeks === null ? null : $earlier]);

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     * @param  list<array<string, mixed>>  $rows  the same days as they read
     * @return list<array<string, mixed>>
     */
    private static function edited(array $stored, array $rows, string $dayId, Closure $edit): array
    {
        foreach ($rows as $index => $row) {
            if ($row['id'] === $dayId) {
                $stored[$index] = $edit($stored[$index], $row);
            }
        }

        return $stored;
    }
}
