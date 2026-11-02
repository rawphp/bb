<?php

use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\SetLog;

// What workout_set_log refuses and stores (rp-clone toggleLogged, draft(of:)).

it('WO-003 WO-025 a set is logged only with its reps, and its weight unless the exercise is bodyweight', function () {
    expect(SetLog::refusal(isLogged: true, isSkipped: false, reps: 10, weight: 80.0, loadType: ExerciseLoadType::Barbell))->toBeNull()
        ->and(SetLog::refusal(true, false, null, 80.0, ExerciseLoadType::Barbell))->toBe('Enter the weight and reps first.')
        ->and(SetLog::refusal(true, false, 10, null, ExerciseLoadType::Dumbbell))->toBe('Enter the weight and reps first.')
        ->and(SetLog::refusal(true, false, 10, null, ExerciseLoadType::Bodyweight))->toBeNull()
        ->and(SetLog::refusal(true, false, 10, null, ExerciseLoadType::BodyweightPlusLoad))->toBeNull()
        // Saving the fields without ticking Log takes whatever was typed.
        ->and(SetLog::refusal(false, false, null, null, ExerciseLoadType::Barbell))->toBeNull();
});

it('WO-020 a skipped set cannot be logged', function () {
    expect(SetLog::refusal(true, true, 10, 80.0, ExerciseLoadType::Barbell))->toBe('Unskip this set to log it.')
        ->and(SetLog::refusal(false, true, 10, 80.0, ExerciseLoadType::Barbell))->toBeNull();
});

it('WO-023 CAP-015 a weight is stored in kg from the unit it was typed in, and none for pure bodyweight', function () {
    expect(SetLog::kg(100.0, WeightUnit::Lb, ExerciseLoadType::Barbell))->toEqualWithDelta(45.359237, 1e-9)
        ->and(SetLog::kg(22.5, WeightUnit::Kg, ExerciseLoadType::Dumbbell))->toBe(22.5)
        ->and(SetLog::kg(10.0, WeightUnit::Kg, ExerciseLoadType::Bodyweight))->toBeNull()
        ->and(SetLog::kg(10.0, WeightUnit::Kg, ExerciseLoadType::BodyweightPlusLoad))->toBe(10.0)
        ->and(SetLog::kg(null, null, ExerciseLoadType::Barbell))->toBeNull();
});
