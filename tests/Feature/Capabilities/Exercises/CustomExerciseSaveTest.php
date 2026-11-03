<?php

use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// The HTTP seam of custom_exercise_save. The rules are unit tests (SaveCustomExerciseTest, TrainInputRulesTest).

function exerciseSaveCall(?User $user, array $input, ?string $key = null): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/custom_exercise_save', $input, ['Idempotency-Key' => $key ?? (string) Str::ulid()]);
}

/** @return array<string, string> a create of a landmine press */
function exerciseSaveInput(array $changes = []): array
{
    return ['name' => 'Landmine Press', 'muscle_group' => 'frontDelts', 'equipment' => 'barbell', 'load_type' => 'barbell', ...$changes];
}

it('CAP-013 AGT-010 none of the four exercise writes is reversible or destructive, and each names the reads it changes', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (['custom_exercise_save', 'exercise_note_set', 'exercise_load_step_set'] as $name) {
        expect(HostDefinition::isReversible($registry->get($name)))->toBeFalse($name)
            ->and(HostDefinition::isDestructive($registry->get($name)))->toBeFalse($name);
    }

    expect(HostDefinition::affects($registry->get('custom_exercise_save')))->toBe(['exercises_show', 'exercise_show', 'exercise_history_show', 'mesocycle_show', 'workout_show'])
        ->and(HostDefinition::affects($registry->get('exercise_note_set')))->toBe(['exercise_show', 'workout_show'])
        ->and(HostDefinition::affects($registry->get('exercise_load_step_set')))->toBe(['exercise_show', 'workout_show']);
});

it('EX-004 refuses custom_exercise_save when signed out', function () {
    exerciseSaveCall(null, exerciseSaveInput())->assertUnauthorized();
});

it('EX-004 creates the user\'s exercise from no exercise_id, and from a null one, with the server defaults', function (array $extra) {
    $user = User::factory()->create();
    $before = Exercise::query()->count();

    $response = exerciseSaveCall($user, exerciseSaveInput($extra))->assertOk();

    $created = Exercise::query()->where('user_id', $user->id)->sole();
    expect(Exercise::query()->count())->toBe($before + 1)
        ->and($created)->name->toBe('Landmine Press')->sfr_rating->value->toBe('medium')->rep_range_min->toBe(10)->rep_range_max->toBe(20)->is_compound->toBeFalse()
        ->and($response->json('data.invalidates'))->toBe([ReadKey::for('exercises_show')]);
})->with(['left out' => [[]], 'null' => [['exercise_id' => null]]]);

it('EX-004 EX-005 edits the user\'s own exercise in place, inserting nothing, and names the reads it changes', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $exercise = Exercise::factory()->custom($user)->create(['name' => 'Press', 'muscle_group' => 'chest']);
    $mesocycle->update(['days' => [['id' => '01JB6Q400A2B3C4D5E6F7G8H9J', 'label' => 'Upper', 'weekday' => 1, 'slots' => [
        ['id' => '01JB6Q401A2B3C4D5E6F7G8H9J', 'exercise_id' => $exercise->id, 'muscle_group' => 'chest', 'sets' => 3],
    ]]]]);
    $session = WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 2, 'day' => 1]);
    WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id]);
    $before = Exercise::query()->count();

    $response = exerciseSaveCall($user, exerciseSaveInput(['exercise_id' => (string) $exercise->id, 'name' => 'Flat Press', 'muscle_group' => 'triceps']))->assertOk();

    expect(Exercise::query()->count())->toBe($before)
        ->and($exercise->fresh())->name->toBe('Flat Press')->muscle_group->value->toBe('triceps')
        ->and($response->json('data.invalidates'))->toBe([
            ReadKey::for('exercises_show'),
            ReadKey::for('exercise_show', ['exercise_id' => (string) $exercise->id]),
            ReadKey::for('exercise_history_show', ['exercise_id' => (string) $exercise->id]),
            ReadKey::for('train_show'),
            ReadKey::for('mesocycle_show', ['mesocycle_id' => (string) $mesocycle->id]),
            ReadKey::for('workout_show', ['mesocycle_id' => (string) $mesocycle->id, 'week' => 2, 'day' => 1]),
        ]);
});

it('EX-004 answers 404 for a library exercise, another user\'s, an unknown id and an id with a leading zero', function () {
    $user = User::factory()->create();
    $own = Exercise::factory()->custom($user)->create();
    $library = Exercise::factory()->create(['name' => 'Squat']);
    $theirs = Exercise::factory()->custom()->create(['name' => 'Theirs']);

    foreach ([(string) $library->id, (string) $theirs->id, '999999', '0'.$own->id] as $id) {
        exerciseSaveCall($user, exerciseSaveInput(['exercise_id' => $id]))->assertNotFound();
    }

    expect($library->fresh()->name)->toBe('Squat')->and($theirs->fresh()->name)->toBe('Theirs');
});

it('EX-004 answers 422 naming exercise_id for an id that is not digits', function () {
    $user = User::factory()->create();
    $own = Exercise::factory()->custom($user)->create();

    foreach (['not-a-number', "{$own->id}abc", " {$own->id}"] as $id) {
        exerciseSaveCall($user, exerciseSaveInput(['exercise_id' => $id]))
            ->assertStatus(422)
            ->assertJsonPath('error.violations.0.field', 'exercise_id');
    }
});

it('EX-004 answers 422 naming the field for a name that is empty', function () {
    exerciseSaveCall(User::factory()->create(), exerciseSaveInput(['name' => '']))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'name');
});

it('CAP-014 returns the first result of a create retried with the same Idempotency-Key, and inserts no second row', function () {
    $user = User::factory()->create();
    $key = (string) Str::ulid();

    $first = exerciseSaveCall($user, exerciseSaveInput(), $key)->assertOk();
    $retry = exerciseSaveCall($user, exerciseSaveInput(), $key)->assertOk();

    expect(Exercise::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($retry->json('data'))->toEqual($first->json('data'));
});
