<?php

namespace App\Support\Workout;

/**
 * PROG-010 to PROG-019, UI-010: saves next week's counts for a finished session's day when they differ from the
 * stored ones, through the mesocycle's model so undo sees it (CAP-013). Returns the toast, null when nothing changed.
 */
final class NextWeekSets
{
    public static function save(WorkoutSnapshot $snapshot): ?string
    {
        $result = $snapshot->progression();
        if ($result === null || ! $result->changed()) {
            return null;
        }
        $mesocycle = $snapshot->mesocycle;
        $mesocycle->days = $result->applying($mesocycle->days ?? []);
        $mesocycle->save();

        return $result->lines === [] ? null : implode("\n", $result->lines);
    }
}
