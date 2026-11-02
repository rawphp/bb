<?php

namespace App\Domain\Progression;

use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;

/** What saving a set's fields refuses and stores (WO-003, WO-020, WO-023, WO-025, CAP-015; rp-clone toggleLogged). */
final class SetLog
{
    /**
     * WO-025: ticking Log counts the set as a working set, so it needs its reps, and its weight unless the exercise is
     * done with the body's weight. A skipped set is not logged. Saving the fields without ticking Log takes what was
     * typed.
     */
    public static function refusal(bool $isLogged, bool $isSkipped, ?int $reps, ?float $weight, ExerciseLoadType $loadType): ?string
    {
        if (! $isLogged) {
            return null;
        }
        if ($isSkipped) {
            return 'Unskip this set to log it.';
        }
        if ($reps === null || ($weight === null && ! self::isBodyweight($loadType))) {
            return 'Enter the weight and reps first.';
        }

        return null;
    }

    /**
     * CAP-015: the weight in kg from the unit it was typed in, for every load type (rp-clone setWeight); only the
     * load maths leaves pure bodyweight out. WO-003: a logged set with no weight lifted no added load, stored as 0 so
     * it never reads back as its target (rp-clone draft(of:)).
     */
    public static function kg(?float $weight, ?WeightUnit $unit, bool $isLogged): ?float
    {
        if ($weight === null) {
            return $isLogged ? 0.0 : null;
        }

        return $unit === WeightUnit::Lb ? UnitConversion::lbToKg($weight) : $weight;
    }

    private static function isBodyweight(ExerciseLoadType $loadType): bool
    {
        return $loadType === ExerciseLoadType::Bodyweight || $loadType === ExerciseLoadType::BodyweightPlusLoad;
    }
}
