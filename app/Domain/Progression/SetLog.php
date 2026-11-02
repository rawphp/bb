<?php

namespace App\Domain\Progression;

use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;

/** What saving a set's fields refuses and stores (WO-003, WO-020, WO-023, WO-025, CAP-015; rp-clone toggleLogged). */
final class SetLog
{
    /** No set holds more reps than this. */
    public const MAX_REPS = 999;

    /** No set holds more weight than this, in kg. */
    public const MAX_KG = 1000.0;

    /**
     * WO-025: ticking Log counts the set as a working set, so it needs its reps, and its weight unless the exercise is
     * done with the body's weight. Saving the fields without ticking Log takes what was typed. A skipped set takes
     * neither until it is unskipped (rp-clone setWeight, setReps, toggleLogged). `$weightKg` is the typed weight in kg
     * ({@see kg()}), bounded at 0 and {@see MAX_KG}.
     */
    public static function refusal(bool $isLogged, bool $isSkipped, ?int $reps, ?float $weightKg, ExerciseLoadType $loadType): ?string
    {
        if ($isSkipped) {
            return 'Unskip this set to log it.';
        }
        if ($reps !== null && $reps > self::MAX_REPS) {
            return 'Enter '.self::MAX_REPS.' reps or fewer.';
        }
        if ($weightKg !== null && $weightKg < 0) {
            return 'Enter a weight of 0 or more.';
        }
        if ($weightKg !== null && $weightKg > self::MAX_KG) {
            return 'Enter a weight of 1000 kg (2204 lb) or less.';
        }
        if (! $isLogged) {
            return null;
        }
        if ($reps === null || ($weightKg === null && ! self::isBodyweight($loadType))) {
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
