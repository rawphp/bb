<?php

namespace App\Support\Workouts;

/**
 * PROG-010 to PROG-019, UI-010: saves next week's counts for a finished session's day when they differ from the
 * stored ones, through the mesocycle's model so undo sees it (CAP-013). Returns the toast, null when nothing changed.
 */
final class NextWeekSets
{
    public static function save(WorkoutSnapshot $snapshot): ?string
    {
        $result = $snapshot->progression();
        $mesocycle = $snapshot->mesocycle;
        if ($result === null || $mesocycle === null || ! $result->changed()) {
            return null;
        }
        $mesocycle->days = $result->applying(array_values($mesocycle->days ?? []));
        $mesocycle->save();

        return $result->lines === [] ? null : implode("\n", $result->lines);
    }
}
