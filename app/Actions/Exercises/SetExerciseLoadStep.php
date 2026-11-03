<?php

namespace App\Actions\Exercises;

use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Models\ExerciseLoadStep;
use App\Models\User;

/** EX-012, SET-001: the user's load step for an exercise, typed in their unit and kept in kg, unrounded. Null clears it. */
final class SetExerciseLoadStep
{
    public function __invoke(User $user, int $exerciseId, ?float $step, WeightUnit $unit): void
    {
        $row = ['user_id' => $user->id, 'exercise_id' => $exerciseId];

        if ($step === null) {
            ExerciseLoadStep::query()->where($row)->delete();

            return;
        }

        ExerciseLoadStep::query()->updateOrCreate($row, ['step_kg' => $unit === WeightUnit::Lb ? UnitConversion::lbToKg($step) : $step]);
    }
}
