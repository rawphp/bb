<?php

namespace App\Support\Workout;

use App\Models\Mesocycle;

/**
 * PROG-010 to PROG-019, UI-010: saves next week's counts for a finished session's day when they differ from the
 * stored ones, through the mesocycle's model so undo sees it (CAP-013). Returns the toast, null when nothing changed.
 *
 * The counts go onto `$locked`, the mesocycle row as the write's lock read it, so another day's counts saved since the
 * snapshot was read are kept.
 */
final class NextWeekSets
{
    public static function save(WorkoutSnapshot $snapshot, Mesocycle $locked): ?string
    {
        $result = $snapshot->progression();
        if ($result === null || ! $result->changed()) {
            return null;
        }
        $locked->days = $result->applying($locked->days ?? []);
        $locked->save();

        return $result->lines === [] ? null : implode("\n", $result->lines);
    }
}
