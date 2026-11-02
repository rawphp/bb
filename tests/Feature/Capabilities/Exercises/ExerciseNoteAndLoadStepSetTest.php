<?php

use App\Capabilities\Support\ReadKey;
use App\Models\Exercise;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// The HTTP seam of exercise_note_set and exercise_load_step_set. The rules are unit tests (SetExerciseNoteTest, SetExerciseLoadStepTest).

function exerciseWriteCall(?User $user, string $name, array $input): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('EX-010 EX-012 refuses both writes when signed out', function (string $name, array $input) {
    exerciseWriteCall(null, $name, $input)->assertUnauthorized();
})->with([
    'exercise_note_set' => ['exercise_note_set', ['exercise_id' => '1', 'note' => 'x']],
    'exercise_load_step_set' => ['exercise_load_step_set', ['exercise_id' => '1', 'step' => 2.5, 'unit' => 'kg']],
]);

it('EX-010 EX-012 answer 404 for another user\'s custom exercise, an unknown one and an id that is not plain digits', function (string $name, array $input) {
    $user = User::factory()->create();
    $library = Exercise::factory()->create();
    $theirs = Exercise::factory()->custom()->create();

    foreach ([(string) $theirs->id, '999999', 'abc', "{$library->id}abc", '0'.$library->id] as $id) {
        exerciseWriteCall($user, $name, ['exercise_id' => $id, ...$input])->assertNotFound();
    }
    exerciseWriteCall($user, $name, ['exercise_id' => (string) $library->id, ...$input])->assertOk();
})->with([
    'exercise_note_set' => ['exercise_note_set', ['note' => 'x']],
    'exercise_load_step_set' => ['exercise_load_step_set', ['step' => 2.5, 'unit' => 'kg']],
]);

it('EX-010 EX-012 answer 422 naming the field for one bad input', function (string $name, array $input, string $field) {
    $exercise = Exercise::factory()->create();

    exerciseWriteCall(User::factory()->create(), $name, ['exercise_id' => (string) $exercise->id, ...$input])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with([
    'an empty note' => ['exercise_note_set', ['note' => ''], 'note'],
    'a zero step' => ['exercise_load_step_set', ['step' => 0, 'unit' => 'kg'], 'step'],
]);

it('EX-010 pins a trimmed note, clears it with null, and names the exercise and the sessions holding it', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $session = WorkoutSession::factory()->for($user)->create(['mesocycle_id' => (string) Str::uuid(), 'week' => 3, 'day' => 2]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id]);

    $set = exerciseWriteCall($user, 'exercise_note_set', ['exercise_id' => (string) $exercise->id, 'note' => '  Pause  '])->assertOk();

    expect(ExerciseNote::query()->where('user_id', $user->id)->value('note'))->toBe('Pause')
        ->and($set->json('data.invalidates'))->toBe([
            ReadKey::for('exercise_show', ['exercise_id' => (string) $exercise->id]),
            ReadKey::for('workout_show', ['mesocycle_id' => (string) $session->mesocycle_id, 'week' => 3, 'day' => 2]),
        ]);

    exerciseWriteCall($user, 'exercise_note_set', ['exercise_id' => (string) $exercise->id, 'note' => null])->assertOk();

    expect(ExerciseNote::query()->count())->toBe(0);
});

it('EX-012 SET-001 stores a step typed in pounds as kilograms, and clears it with null', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['weight_unit' => 'lb']);
    $exercise = Exercise::factory()->create();

    $set = exerciseWriteCall($user, 'exercise_load_step_set', ['exercise_id' => (string) $exercise->id, 'step' => 10, 'unit' => 'lb'])->assertOk();

    expect(ExerciseLoadStep::query()->where('user_id', $user->id)->value('step_kg'))->toEqualWithDelta(4.5359237, 0.000001)
        ->and($set->json('data.invalidates.0'))->toBe(ReadKey::for('exercise_show', ['exercise_id' => (string) $exercise->id]));

    exerciseWriteCall($user, 'exercise_load_step_set', ['exercise_id' => (string) $exercise->id, 'step' => null, 'unit' => 'lb'])->assertOk();

    expect(ExerciseLoadStep::query()->count())->toBe(0);
});

it('EX-012 accepts a step for a reps-only exercise: it is stored and has no effect', function () {
    $user = User::factory()->create();
    $pushUp = Exercise::factory()->create(['load_type' => 'bodyweight']);

    exerciseWriteCall($user, 'exercise_load_step_set', ['exercise_id' => (string) $pushUp->id, 'step' => 2.5, 'unit' => 'kg'])->assertOk();

    expect(ExerciseLoadStep::query()->where('user_id', $user->id)->value('step_kg'))->toBe(2.5);
});
