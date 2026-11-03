<?php

namespace App\Support\Workout;

use App\Domain\Mesocycle\MesocycleRules;
use App\Models\Mesocycle;
use Closure;

/**
 * A change to one day of a mesocycle, written where that day runs (MESO-045, EX-008, WO-031): the rotation, and each
 * week stored on its own (SCH-039). Only the changed day is touched, found by its id.
 */
final class MesocycleDay
{
    /**
     * MESO-045 "Apply to all remaining weeks", EX-008 "Rest of the mesocycle": the session's day changes from its week on.
     * The weeks before it are stored first as they ran (SCH-039, as AddMesocycleDay does), so they keep their slots; the
     * rotation and every week stored from the session's on take the change. Nothing is saved when the slots stay as
     * they are.
     *
     * @param  Closure(list<array{id: string, exercise_id: ?int, muscle_group: string, sets: int, carried: ?array{weight_kg: float, reps: int}}>): list<array<string, mixed>>  $slots
     * @return bool whether anything changed
     */
    public static function slots(WorkoutSnapshot $snapshot, Closure $slots): bool
    {
        $mesocycle = $snapshot->mesocycle;
        $fromWeek = $snapshot->week - 1;
        $edit = function (array $stored, array $read) use ($slots) {
            $changed = $slots($read['slots']);
            if ($changed === $read['slots']) {
                return $stored;
            }
            $stored['id'] = $read['id'];
            $stored['slots'] = $changed;

            return $stored;
        };

        $stored = $mesocycle->days ?? [];
        $days = self::edited($stored, $mesocycle->dayRows(), $snapshot->plan()->id, $edit);
        $frozen = MesocycleRules::freezingWeeks($snapshot->train->rotationAsRun($mesocycle), $mesocycle->earlierWeekRows(), $mesocycle->laterWeekIds(), $fromWeek);
        $weeks = $frozen['earlier_weeks'];
        $weeksChanged = false;
        foreach ($weeks as $week => $rows) {
            if ($week >= $fromWeek) {
                $weeks[$week] = self::edited($rows, $rows, $snapshot->plan()->id, $edit);
                $weeksChanged = $weeksChanged || $weeks[$week] !== $rows;
            }
        }
        if ($days === $stored && ! $weeksChanged) {
            return false;
        }
        $mesocycle->update(['days' => $days, 'earlier_weeks' => $weeks, 'later_weeks' => $frozen['later_weeks']]);

        return true;
    }

    /** WO-031: the day's name in every week of the mesocycle. A week where it already reads so is left as stored. */
    public static function label(Mesocycle $mesocycle, string $dayId, string $label): bool
    {
        $edit = function (array $stored, array $read) use ($label) {
            if ($read['label'] === $label) {
                return $stored;
            }
            $stored['id'] = $read['id'];
            $stored['label'] = $label;

            return $stored;
        };
        $days = self::edited($mesocycle->days ?? [], $mesocycle->dayRows(), $dayId, $edit);
        $earlier = $mesocycle->earlier_weeks ?? [];
        foreach ($mesocycle->earlierWeekRows() as $week => $rows) {
            $earlier[$week] = self::edited($earlier[$week] ?? [], $rows, $dayId, $edit);
        }
        if ($days === ($mesocycle->days ?? []) && $earlier === ($mesocycle->earlier_weeks ?? [])) {
            return false;
        }
        $mesocycle->update(['days' => $days, 'earlier_weeks' => $mesocycle->earlier_weeks === null ? null : $earlier]);

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     * @param  list<array<string, mixed>>  $rows  the same days as they read
     * @param  Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $edit  the stored day and the day as it reads → the stored day changed
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
