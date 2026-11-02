<?php

use App\Capabilities\Support\HostDefinition;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutIds;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// The HTTP seam of the set and note writes, and the offline order across them (OFF-021). The rules are unit tests
// (WorkoutSetWritesTest, SetLogTest). Meso 2 runs 5 weeks from Monday 7 September; Monday 21 September is week 3.

afterEach(fn () => Carbon::setTestNow());

function writeOver(?User $user, string $capability, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$capability}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** @return array{0: User, 1: array<string, mixed>} a user with week 3 day 1 started, and workout_show's payload */
function startedOver(): array
{
    Carbon::setTestNow('2026-09-21 17:40:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    writeOver($user, 'workout_start', ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)])->assertOk();

    return [$user, writeOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data')];
}

/** Each write, an input naming an existing set or exercise, and an input with one bad field. */
dataset('workout writes', [
    'workout_set_log' => ['workout_set_log', fn (string $set, string $block) => ['set_id' => $set, 'weight' => ['value' => 80, 'unit' => 'kg'], 'reps' => 10, 'is_logged' => true], ['weight' => ['value' => 80, 'unit' => 'stone']], 'weight.unit'],
    'workout_set_add' => ['workout_set_add', fn (string $set, string $block) => ['set_id' => strtolower((string) Str::ulid()), 'workout_exercise_id' => $block, 'after_set_id' => $set, 'set_type' => 'myorep'], ['set_type' => 'superset'], 'set_type'],
    'workout_set_delete' => ['workout_set_delete', fn (string $set, string $block) => ['set_id' => $set], ['set_id' => 'nope'], 'set_id'],
    'workout_set_skip' => ['workout_set_skip', fn (string $set, string $block) => ['set_id' => $set, 'is_skipped' => true], ['is_skipped' => 'yes'], 'is_skipped'],
    'workout_set_type_set' => ['workout_set_type_set', fn (string $set, string $block) => ['set_id' => $set, 'set_type' => 'regular_down'], ['set_type' => 'superset'], 'set_type'],
    'workout_exercise_note_set' => ['workout_exercise_note_set', fn (string $set, string $block) => ['workout_exercise_id' => $block, 'note' => 'Slow eccentrics'], ['note' => str_repeat('x', 1001)], 'note'],
]);

it('WO-020 WO-022 refuses each write when signed out', function (string $capability, Closure $input) {
    writeOver(null, $capability, $input('01JB6Q3V8T2K9WZ4M5N7P1R3S5', '01JB6Q3V8T2K9WZ4M5N7P1R3S6'))->assertUnauthorized();
})->with('workout writes');

it('WO-020 WO-022 answers 404 for another user\'s set or exercise, and changes nothing', function (string $capability, Closure $input) {
    [, $theirs] = startedOver();
    $block = $theirs['exercises'][0];
    $before = [WorkoutSet::query()->get()->toArray(), WorkoutExercise::query()->get()->toArray()];

    writeOver(TrainSeed::user(), $capability, $input($block['sets'][0]['id'], $block['id']))->assertNotFound();

    expect([WorkoutSet::query()->get()->toArray(), WorkoutExercise::query()->get()->toArray()])->toBe($before);
})->with('workout writes');

it('WO-020 WO-022 answers 422 naming the field for one bad input', function (string $capability, Closure $input, array $bad, string $field) {
    [$user, $shown] = startedOver();
    $block = $shown['exercises'][0];

    writeOver($user, $capability, array_replace($input($block['sets'][0]['id'], $block['id']), $bad))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with('workout writes');

it('AGT-010 only deleting a set is destructive; no workout write is reversible, and each names the reads it changes', function () {
    $registry = app(CapabilityRegistry::class);
    $sets = ['workout_show', 'today_show', 'week_show', 'train_show', 'mesocycle_show'];

    foreach (['workout_set_log', 'workout_set_add', 'workout_set_delete', 'workout_set_skip', 'workout_set_type_set', 'workout_exercise_note_set'] as $name) {
        $definition = $registry->get($name);
        expect(HostDefinition::isDestructive($definition))->toBe($name === 'workout_set_delete', $name)
            ->and(HostDefinition::isReversible($definition))->toBeFalse($name)
            ->and(HostDefinition::affects($definition))->toBe($name === 'workout_exercise_note_set' ? ['workout_show'] : $sets, $name);
    }
});

it('CAP-019 workout_set_add answers 404 for another user\'s set id under the caller\'s own exercise, and changes nothing', function () {
    [, $theirs] = startedOver();
    [$me, $mine] = startedOver();
    $before = [WorkoutSet::query()->orderBy('id')->get()->toArray(), WorkoutExercise::query()->orderBy('id')->get()->toArray()];

    writeOver($me, 'workout_set_add', [
        'set_id' => $theirs['exercises'][0]['sets'][0]['id'], 'workout_exercise_id' => $mine['exercises'][0]['id'], 'after_set_id' => null, 'set_type' => 'regular_straight',
    ])->assertNotFound();

    expect([WorkoutSet::query()->orderBy('id')->get()->toArray(), WorkoutExercise::query()->orderBy('id')->get()->toArray()])->toBe($before);
});

it('CAP-019 workout_set_add answers 404 for the id of a set since deleted, not a server error', function () {
    [$user, $shown] = startedOver();
    $add = ['set_id' => strtolower((string) Str::ulid()), 'workout_exercise_id' => $shown['exercises'][0]['id'], 'after_set_id' => null, 'set_type' => 'regular_straight'];
    writeOver($user, 'workout_set_add', $add)->assertOk();
    writeOver($user, 'workout_set_delete', ['set_id' => $add['set_id']])->assertOk();

    writeOver($user, 'workout_set_add', $add)->assertNotFound();

    expect(WorkoutSet::query()->where('workout_exercise_id', $shown['exercises'][0]['id'])->count())->toBe(3);
});

it('WO-003 refuses a rep count no set can hold with a plain message, not a server error', function () {
    [$user, $shown] = startedOver();
    $set = $shown['exercises'][0]['sets'][0]['id'];

    writeOver($user, 'workout_set_log', ['set_id' => $set, 'weight' => ['value' => 80, 'unit' => 'kg'], 'reps' => 70000, 'is_logged' => false])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Enter 999 reps or fewer.');

    expect(WorkoutSet::query()->find($set)->actual_reps)->toBeNull();
});

it('WO-020 refuses a write to a session not started yet with a plain message', function () {
    [$user, $shown] = startedOver();
    WorkoutSet::query()->find($shown['exercises'][0]['sets'][0]['id'])->session->update(['actual_start_at' => null]);

    writeOver($user, 'workout_set_skip', ['set_id' => $shown['exercises'][0]['sets'][0]['id'], 'is_skipped' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Start the workout first.');
});

it('OFF-021 a session started offline takes the sets logged offline: the ids the screen showed before the start are the rows after it', function () {
    Carbon::setTestNow('2026-09-21 17:40:00');
    Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 10,
    ]);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $show = fn () => writeOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->assertOk()->json('data');
    $before = $show();
    $set = $before['exercises'][0]['sets'][1];

    writeOver($user, 'workout_start', ['workout_id' => $before['workout_id']])->assertOk();
    $logged = writeOver($user, 'workout_set_log', ['set_id' => $set['id'], 'weight' => ['value' => 60, 'unit' => 'kg'], 'reps' => 12, 'is_logged' => true])->assertOk();
    writeOver($user, 'workout_exercise_note_set', ['workout_exercise_id' => $before['exercises'][0]['id'], 'note' => 'Pause at the chest'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":3}']);
    $after = $show();

    expect($logged->json('data.invalidates.0'))->toBe('workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":3}')
        ->and($after['status'])->toBe('in_progress')
        ->and($after['workout_id'])->toBe($before['workout_id'])
        ->and($after['exercises'][0]['id'])->toBe($before['exercises'][0]['id'])
        ->and(array_column($after['exercises'][0]['sets'], 'id'))->toBe(array_column($before['exercises'][0]['sets'], 'id'))
        ->and($after['exercises'][0]['sets'][1])->toMatchArray(['is_logged' => true, 'reps' => 12])
        ->and($after['exercises'][0]['sets'][1]['weight']['value'])->toEqual(60)
        ->and($after['exercises'][0]['session_note'])->toBe('Pause at the chest');
});

it('WO-003 CAP-011 logging a set names the reads it changes from the session row, without loading the mesocycle\'s sessions and sets', function () {
    [$user, $shown] = startedOver();
    $mesocycleId = WorkoutSet::query()->find($shown['exercises'][0]['sets'][0]['id'])->session->mesocycle_id;
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (! preg_match('/`(capabilities_\w+|audit_records)`/', $query->sql)) {
            $queries[] = $query->sql;
        }
    });

    $keys = writeOver($user, 'workout_set_log', ['set_id' => $shown['exercises'][0]['sets'][0]['id'], 'weight' => ['value' => 80, 'unit' => 'kg'], 'reps' => 10, 'is_logged' => true])
        ->assertOk()->json('data.invalidates');

    // The write reads the set, its session and exercise, and saves the set; the keys need the plan, the profile and
    // the mesocycle. A Train snapshot alone runs nine queries, the session's sets among them.
    expect(count($queries))->toBeLessThanOrEqual(8, implode("\n", $queries))
        ->and($keys)->toBe([
            'workout_show{"day":1,"mesocycle_id":"'.$mesocycleId.'","week":3}',
            'train_show{}',
            "mesocycle_show{\"mesocycle_id\":\"{$mesocycleId}\"}",
            'today_show{"date":"2026-09-21"}',
            'today_show{}',
            ...array_map(fn (string $day) => 'week_show{"date":"2026-09-'.$day.'"}', ['21', '22', '23', '24', '25', '26', '27']),
            'week_show{}',
        ]);
});
