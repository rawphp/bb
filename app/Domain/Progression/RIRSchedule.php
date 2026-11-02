<?php

namespace App\Domain\Progression;

use App\Domain\Defaults\TBL023TargetRIR;

/**
 * The weekly target-RIR schedule for a mesocycle (PROG-030, TBL-023).
 *
 * Weeks are 1-based and the mesocycle length includes the deload week (PROG-040). The schedule is derived from the
 * length alone and is never stored, so it cannot be edited (PROG-031) and follows the length when a mesocycle is
 * shortened or its deload moves earlier (MESO-042, REV-011). It answers for the weeks ahead: a week already trained
 * keeps the targets its sets were logged with.
 */
final class RIRSchedule
{
    /** PROG-040: the last week of every mesocycle is the deload. */
    public static function isDeload(int $week, int $mesocycleWeeks): bool
    {
        return $week === $mesocycleWeeks;
    }

    /**
     * Target RIR for a training week, from the TBL-023 row for $mesocycleWeeks - 1 training weeks. Null for the
     * deload week, for weeks out of range, and when TBL-023 has no row for that length.
     */
    public static function targetRIR(int $week, int $mesocycleWeeks): ?int
    {
        if ($week < 1 || $week >= $mesocycleWeeks) {
            return null;
        }

        return TBL023TargetRIR::rir($mesocycleWeeks - 1)[$week - 1] ?? null;
    }
}
