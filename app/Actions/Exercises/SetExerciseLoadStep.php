<?php

namespace App\Actions\Exercises;

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\ExerciseLoadStep;
use App\Models\User;
use DomainException;

/**
 * EX-012, SET-001: the user's load step for an exercise, typed in their unit and kept in kg, unrounded. Null clears it.
 * A reps-only (bodyweight) exercise has no load, so it takes no step: one kept for it would apply unseen if its load
 * type changed later.
 */
final class SetExerciseLoadStep
{
    public const REPS_ONLY = 'A bodyweight exercise is reps only, so it has no load step.';

    public function __invoke(User $user, Exercise $exercise, ?float $step, WeightUnit $unit): void
    {
        $row = ['user_id' => $user->id, 'exercise_id' => $exercise->id];

        if ($step === null) {
            ExerciseLoadStep::query()->where($row)->delete();

            return;
        }

        if ($exercise->load_type->tbl040() === LoadType::Bodyweight) {
            throw new DomainException(self::REPS_ONLY);
        }

        ExerciseLoadStep::query()->updateOrCreate($row, ['step_kg' => $unit === WeightUnit::Lb ? UnitConversion::lbToKg($step) : $step]);
    }
}
