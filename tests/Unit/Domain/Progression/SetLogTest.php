<?php

use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\SetLog;

// What workout_set_log refuses and stores (rp-clone toggleLogged, draft(of:)).

it('WO-003 WO-025 a set is logged only with its reps, and its weight unless the exercise is bodyweight', function () {
    expect(SetLog::refusal(isLogged: true, isSkipped: false, reps: 10, weightKg: 80.0, loadType: ExerciseLoadType::Barbell))->toBeNull()
        ->and(SetLog::refusal(true, false, null, 80.0, ExerciseLoadType::Barbell))->toBe('Enter the weight and reps first.')
        ->and(SetLog::refusal(true, false, 10, null, ExerciseLoadType::Dumbbell))->toBe('Enter the weight and reps first.')
        ->and(SetLog::refusal(true, false, 10, null, ExerciseLoadType::Bodyweight))->toBeNull()
        ->and(SetLog::refusal(true, false, 10, null, ExerciseLoadType::BodyweightPlusLoad))->toBeNull()
        // Saving the fields without ticking Log takes whatever was typed.
        ->and(SetLog::refusal(false, false, null, null, ExerciseLoadType::Barbell))->toBeNull();
});

it('WO-020 a skipped set cannot be logged, nor its weight or reps changed', function () {
    // rp-clone setWeight, setReps and toggleLogged all leave a skipped set as it is.
    expect(SetLog::refusal(true, true, 10, 80.0, ExerciseLoadType::Barbell))->toBe('Unskip this set to log it.')
        ->and(SetLog::refusal(false, true, 10, 80.0, ExerciseLoadType::Barbell))->toBe('Unskip this set to log it.');
});

it('WO-003 a rep count no set can hold is refused', function (bool $isLogged) {
    expect(SetLog::refusal($isLogged, false, 1000, 80.0, ExerciseLoadType::Barbell))->toBe('Enter 999 reps or fewer.')
        ->and(SetLog::refusal($isLogged, false, 999, 80.0, ExerciseLoadType::Barbell))->toBeNull();
})->with(['logging' => true, 'typing' => false]);

it('WO-023 CAP-015 a weight is stored in kg from the unit it was typed in, whatever the load type', function () {
    // rp-clone setWeight stores what was typed for every load type; only the load maths leaves bodyweight out.
    expect(SetLog::kg(100.0, WeightUnit::Lb, isLogged: false))->toEqualWithDelta(45.359237, 1e-9)
        ->and(SetLog::kg(22.5, WeightUnit::Kg, isLogged: true))->toBe(22.5)
        ->and(SetLog::kg(null, null, isLogged: false))->toBeNull();
});

it('WO-003 a logged set with no weight stores no added load as 0', function () {
    // rp-clone draft(of:): a logged set's weight is `weight ?? 0`, so it never reads back as its target.
    expect(SetLog::kg(null, null, isLogged: true))->toBe(0.0);
});

it('WO-003 CAP-015 a weight below 0 or above 1000 kg is refused', function (bool $isLogged) {
    // The weight arrives in kg, converted from the unit it was typed in.
    expect(SetLog::refusal($isLogged, false, 8, -0.5, ExerciseLoadType::Barbell))->toBe('Enter a weight of 0 or more.')
        ->and(SetLog::refusal($isLogged, false, 8, 1000.01, ExerciseLoadType::Barbell))->toBe('Enter a weight of 1000 kg (2204 lb) or less.')
        ->and(SetLog::refusal($isLogged, false, 8, 1000.0, ExerciseLoadType::Barbell))->toBeNull()
        ->and(SetLog::refusal($isLogged, false, 8, 0.0, ExerciseLoadType::BodyweightPlusLoad))->toBeNull();
})->with(['logging' => true, 'typing' => false]);
