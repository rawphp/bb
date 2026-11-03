<?php

use App\Actions\Exercises\SetExerciseLoadStep;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\ExerciseLoadStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function storedStep(User $user, Exercise $exercise): ?float
{
    return ExerciseLoadStep::query()->where('user_id', $user->id)->where('exercise_id', $exercise->id)->value('step_kg');
}

it('EX-012 stores a step typed in kilograms as it is, one row per user and exercise', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['load_type' => 'barbell']);

    (new SetExerciseLoadStep)($user, $exercise, 4.5, WeightUnit::Kg);
    (new SetExerciseLoadStep)($user, $exercise, 1.25, WeightUnit::Kg);

    expect(storedStep($user, $exercise))->toBe(1.25)
        ->and(ExerciseLoadStep::query()->count())->toBe(1);
});

it('SET-001 stores a step typed in pounds as kilograms, unrounded', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['load_type' => 'barbell']);

    (new SetExerciseLoadStep)($user, $exercise, 10, WeightUnit::Lb);

    expect(storedStep($user, $exercise))->toEqualWithDelta(10 * 0.45359237, 0.0000001);
});

it('EX-012 clears the step on null, back to the TBL-040 default, and leaves another user\'s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $exercise = Exercise::factory()->create(['load_type' => 'barbell']);
    ExerciseLoadStep::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'step_kg' => 3]);
    ExerciseLoadStep::factory()->create(['user_id' => $other->id, 'exercise_id' => $exercise->id, 'step_kg' => 4]);

    (new SetExerciseLoadStep)($user, $exercise, null, WeightUnit::Kg);

    expect(storedStep($user, $exercise))->toBeNull()
        ->and(storedStep($other, $exercise))->toBe(4.0);
});

it('EX-012 refuses a step for a reps-only exercise and stores nothing', function () {
    $user = User::factory()->create();
    $pushUp = Exercise::factory()->create(['load_type' => 'bodyweight']);

    expect(fn () => (new SetExerciseLoadStep)($user, $pushUp, 2.5, WeightUnit::Kg))
        ->toThrow(DomainException::class, SetExerciseLoadStep::REPS_ONLY)
        ->and(storedStep($user, $pushUp))->toBeNull();
});

it('EX-012 stores a step for a bodyweight-plus-load exercise, which carries a load', function () {
    $user = User::factory()->create();
    $dip = Exercise::factory()->create(['load_type' => 'bodyweightPlusLoad']);

    (new SetExerciseLoadStep)($user, $dip, 1.25, WeightUnit::Kg);

    expect(storedStep($user, $dip))->toBe(1.25);
});

it('EX-012 clears a step kept for an exercise that is now reps only', function () {
    $user = User::factory()->create();
    $pushUp = Exercise::factory()->create(['load_type' => 'bodyweight']);
    ExerciseLoadStep::factory()->create(['user_id' => $user->id, 'exercise_id' => $pushUp->id, 'step_kg' => 2.5]);

    (new SetExerciseLoadStep)($user, $pushUp, null, WeightUnit::Kg);

    expect(storedStep($user, $pushUp))->toBeNull();
});
