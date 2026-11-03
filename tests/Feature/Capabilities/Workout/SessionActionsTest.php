<?php

use App\Capabilities\Support\HostDefinition;
use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The HTTP seam of the session actions. The rules are unit tests (DayEditsTest, SessionActionsTest). Meso 2 runs 5
// weeks from Monday 7 September; Monday 21 September is week 3, whose day 1 is Upper A (Bench Press).

afterEach(fn () => Carbon::setTestNow());

function actOver(?User $user, string $capability, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$capability}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/**
 * @param  bool  $withIncline  Upper A also holds Incline Dumbbell Press, two sets after Bench Press
 * @return array{0: User, 1: Mesocycle, 2: array<string, mixed>} a user with week 3 day 1 started, its mesocycle and workout_show's payload
 */
function actStarted(bool $withIncline = false): array
{
    Carbon::setTestNow('2026-09-21 17:40:00');
    TrainSeed::exercise('Incline Dumbbell Press', MuscleGroup::Chest);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    if ($withIncline) {
        $days = $mesocycle->days;
        $days[0]['slots'][] = ['id' => 'slot-incline', 'exercise_id' => (int) actIncline(), 'muscle_group' => 'chest', 'sets' => 2];
        $mesocycle->update(['days' => $days]);
    }
    actOver($user, 'workout_start', ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)])->assertOk();

    return [$user, $mesocycle, actOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data')];
}

function actIncline(): string
{
    return (string) Exercise::query()->where('name', 'Incline Dumbbell Press')->value('id');
}

/** Each session action, an input naming the session or its exercise, and an input with one bad field. */
dataset('session actions', [
    'exercise_swap' => ['exercise_swap', fn (string $workout, string $block) => ['workout_exercise_id' => $block, 'new_workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => actIncline(), 'scope' => 'this_session'], ['scope' => 'forever'], 'scope'],
    'workout_day_relabel' => ['workout_day_relabel', fn (string $workout, string $block) => ['workout_id' => $workout, 'label' => 'Push'], ['label' => ''], 'label'],
    'workout_exercise_add' => ['workout_exercise_add', fn (string $workout, string $block) => ['workout_id' => $workout, 'workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => actIncline(), 'remaining_weeks' => false], ['remaining_weeks' => 'yes'], 'remaining_weeks'],
    'workout_exercise_remove' => ['workout_exercise_remove', fn (string $workout, string $block) => ['workout_exercise_id' => $block], ['workout_exercise_id' => 'nope'], 'workout_exercise_id'],
    'workout_exercise_reorder' => ['workout_exercise_reorder', fn (string $workout, string $block) => ['workout_exercise_id' => $block, 'position' => 1, 'remaining_weeks' => true], ['position' => 0], 'position'],
    'workout_skip' => ['workout_skip', fn (string $workout, string $block) => ['workout_id' => $workout], ['workout_id' => 'nope'], 'workout_id'],
]);

it('WO-022 WO-031 WO-050 refuses each session action when signed out', function (string $capability, Closure $input) {
    actOver(null, $capability, $input('01JB6Q3V8T2K9WZ4M5N7P1R3S5', '01JB6Q3V8T2K9WZ4M5N7P1R3S6'))->assertUnauthorized();
})->with('session actions');

it('WO-022 WO-031 WO-050 answers 404 for another user\'s session or exercise, and changes nothing', function (string $capability, Closure $input) {
    [, $theirs, $shown] = actStarted();
    $before = [WorkoutSession::query()->get()->toArray(), WorkoutExercise::query()->get()->toArray(), WorkoutSet::query()->get()->toArray(), $theirs->fresh()->days];

    actOver(TrainSeed::user(), $capability, $input($shown['workout_id'], $shown['exercises'][0]['id']))->assertNotFound();

    expect([WorkoutSession::query()->get()->toArray(), WorkoutExercise::query()->get()->toArray(), WorkoutSet::query()->get()->toArray(), $theirs->fresh()->days])->toBe($before);
})->with('session actions');

it('WO-022 WO-031 WO-050 answers 422 naming the field for one bad input', function (string $capability, Closure $input, array $bad, string $field) {
    [$user, , $shown] = actStarted();

    actOver($user, $capability, array_replace($input($shown['workout_id'], $shown['exercises'][0]['id']), $bad))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with('session actions');

it('WO-031 EX-008 answers 404 for another user\'s custom exercise', function (string $capability, Closure $input) {
    [$user, , $shown] = actStarted();
    $theirs = Exercise::factory()->custom(User::factory()->create())->create(['muscle_group' => MuscleGroup::Chest]);

    actOver($user, $capability, ['exercise_id' => (string) $theirs->id] + $input($shown['workout_id'], $shown['exercises'][0]['id']))->assertNotFound();

    expect(WorkoutExercise::query()->count())->toBe(1);
})->with([
    'exercise_swap' => ['exercise_swap', fn (string $workout, string $block) => ['workout_exercise_id' => $block, 'new_workout_exercise_id' => strtolower((string) Str::ulid()), 'scope' => 'this_session']],
    'workout_exercise_add' => ['workout_exercise_add', fn (string $workout, string $block) => ['workout_id' => $workout, 'workout_exercise_id' => strtolower((string) Str::ulid()), 'remaining_weeks' => false]],
]);

it('AGT-010 WO-050 removing an exercise and skipping a workout are destructive; no session action is reversible, and each names the reads it changes', function () {
    $registry = app(CapabilityRegistry::class);
    $workout = ['workout_show', 'today_show', 'week_show', 'train_show', 'mesocycle_show'];

    foreach (['exercise_swap', 'workout_day_relabel', 'workout_exercise_add', 'workout_exercise_remove', 'workout_exercise_reorder', 'workout_skip'] as $name) {
        $definition = $registry->get($name);
        expect(HostDefinition::isDestructive($definition))->toBe(in_array($name, ['workout_exercise_remove', 'workout_skip'], true), $name)
            ->and(HostDefinition::isReversible($definition))->toBeFalse($name)
            ->and(HostDefinition::affects($definition))->toBe($name === 'workout_skip' ? [...$workout, 'day_optimize_show', 'day_redistribute_show', 'day_adjust_show'] : $workout, $name);
    }
});

it('MESO-045 an exercise added to the remaining weeks refreshes the workout of each later week that runs the day', function () {
    [$user, $mesocycle, $shown] = actStarted();

    $keys = actOver($user, 'workout_exercise_add', ['workout_id' => $shown['workout_id'], 'workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => actIncline(), 'remaining_weeks' => true])
        ->assertOk()->json('data.invalidates');

    $workouts = array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'workout_show')));
    expect($workouts)->toBe(array_map(fn (int $week) => 'workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":'.$week.'}', [3, 4, 5]))
        ->and($keys)->toContain('train_show{}', "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}", 'today_show{"date":"2026-09-21"}');
});

it('WO-031 a relabelled day refreshes the workout of every week that runs it', function () {
    [$user, $mesocycle, $shown] = actStarted();

    $keys = actOver($user, 'workout_day_relabel', ['workout_id' => $shown['workout_id'], 'label' => 'Push'])->assertOk()->json('data.invalidates');

    $workouts = array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'workout_show')));
    expect($workouts)->toBe(array_map(fn (int $week) => 'workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":'.$week.'}', [3, 1, 2, 4, 5]))
        ->and(actOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.day_label'))->toBe('Push');
});

it('WO-050 SCH-053 workout_skip skips today\'s workout, says which meals changed, and refreshes the day', function () {
    Carbon::setTestNow('2026-09-21 09:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-09-21');

    $data = actOver($user, 'workout_skip', ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)])->assertOk()->json('data');

    expect($data['toast'])->toBe('Meals updated for the skipped workout')
        ->and($data['invalidates'])->toContain(
            'workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":3}',
            'today_show{"date":"2026-09-21"}',
            'day_redistribute_show{"date":"2026-09-21"}',
            'week_show{}',
        )
        ->and(actOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.status'))->toBe('skipped');
});

it('EX-008 swaps an exercise over HTTP and the new block shows under the client\'s id', function () {
    [$user, $mesocycle, $shown] = actStarted();
    $new = strtolower((string) Str::ulid());

    actOver($user, 'exercise_swap', ['workout_exercise_id' => $shown['exercises'][0]['id'], 'new_workout_exercise_id' => $new, 'exercise_id' => actIncline(), 'scope' => 'rest_of_mesocycle'])
        ->assertOk();

    $after = actOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.exercises');
    expect(array_column($after, 'id'))->toBe([$new])
        ->and(array_column($after, 'name'))->toBe(['Incline Dumbbell Press'])
        ->and($mesocycle->fresh()->dayRows()[0]['slots'][0]['exercise_id'])->toBe((int) actIncline());
});

/** @param  list<string>  $keys @return list<int> the weeks whose workout_show is named */
function actWorkoutWeeks(array $keys): array
{
    $weeks = [];
    foreach ($keys as $key) {
        if (preg_match('/^workout_show\{.*"week":(\d+)\}$/', $key, $match) === 1) {
            $weeks[] = (int) $match[1];
        }
    }

    return $weeks;
}

it('EX-008 a swap for this session only leaves the mesocycle alone and refreshes only this workout', function () {
    [$user, $mesocycle, $shown] = actStarted();
    $days = $mesocycle->fresh()->days;

    $keys = actOver($user, 'exercise_swap', ['workout_exercise_id' => $shown['exercises'][0]['id'], 'new_workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => actIncline(), 'scope' => 'this_session'])
        ->assertOk()->json('data.invalidates');

    expect($mesocycle->fresh()->days)->toBe($days)
        ->and(actWorkoutWeeks($keys))->toBe([3]);
});

it('EX-008 a swap for the rest of the mesocycle refreshes the workout and Today of each later week that runs the day', function () {
    [$user, , $shown] = actStarted();

    $keys = actOver($user, 'exercise_swap', ['workout_exercise_id' => $shown['exercises'][0]['id'], 'new_workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => actIncline(), 'scope' => 'rest_of_mesocycle'])
        ->assertOk()->json('data.invalidates');

    // Weeks 4 and 5 run Upper A on Mondays 28 September and 5 October.
    expect(actWorkoutWeeks($keys))->toBe([3, 4, 5])
        ->and($keys)->toContain('today_show{"date":"2026-09-28"}', 'today_show{"date":"2026-10-05"}');
});

it('WO-031 an exercise added to this session only leaves the mesocycle alone', function () {
    [$user, $mesocycle, $shown] = actStarted();
    $days = $mesocycle->fresh()->days;

    $keys = actOver($user, 'workout_exercise_add', ['workout_id' => $shown['workout_id'], 'workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => actIncline(), 'remaining_weeks' => false])
        ->assertOk()->json('data.invalidates');

    expect($mesocycle->fresh()->days)->toBe($days)
        ->and(actWorkoutWeeks($keys))->toBe([3])
        ->and(WorkoutExercise::query()->count())->toBe(2);
});

it('WO-031 MESO-045 a reorder with remaining weeks writes the order to the day and refreshes the later weeks', function () {
    [$user, $mesocycle, $shown] = actStarted(withIncline: true);

    $keys = actOver($user, 'workout_exercise_reorder', ['workout_exercise_id' => $shown['exercises'][1]['id'], 'position' => 1, 'remaining_weeks' => true])
        ->assertOk()->json('data.invalidates');

    expect(array_column($mesocycle->fresh()->dayRows()[0]['slots'], 'id'))->toBe(['slot-incline', '01JB6Q401A2B3C4D5E6F7G8H9J'])
        ->and(actWorkoutWeeks($keys))->toBe([3, 4, 5])
        ->and(array_column(actOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.exercises'), 'name'))
        ->toBe(['Incline Dumbbell Press', 'Bench Press']);
});

it('WO-022 workout_exercise_remove takes the exercise out of the session', function () {
    [$user, $mesocycle, $shown] = actStarted(withIncline: true);

    $keys = actOver($user, 'workout_exercise_remove', ['workout_exercise_id' => $shown['exercises'][0]['id']])->assertOk()->json('data.invalidates');

    expect(actWorkoutWeeks($keys))->toBe([3])
        ->and(array_column(actOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.exercises'), 'name'))
        ->toBe(['Incline Dumbbell Press']);
});

it('WO-031 relabelling from a session not started refreshes every week of the day and today, and starts nothing', function () {
    Carbon::setTestNow('2026-09-20 09:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    $keys = actOver($user, 'workout_day_relabel', ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1), 'label' => 'Push'])->assertOk()->json('data.invalidates');

    expect(actWorkoutWeeks($keys))->toBe([3, 1, 2, 4, 5])
        ->and($keys)->toContain('today_show{"date":"2026-09-20"}', 'today_show{}', 'today_show{"date":"2026-09-28"}')
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('CAP-019 the new exercise id of an add or a swap answers 404 when it is another user\'s, or the caller\'s own removed one', function (string $capability, string $owner) {
    [, , $theirs] = actStarted();
    [$user, , $shown] = actStarted(withIncline: true);
    $id = $owner === 'theirs' ? $theirs['exercises'][0]['id'] : $shown['exercises'][1]['id'];
    if ($owner === 'removed') {
        actOver($user, 'workout_exercise_remove', ['workout_exercise_id' => $id])->assertOk();
    }
    $before = [WorkoutExercise::query()->withTrashed()->orderBy('id')->get()->toArray(), WorkoutSet::query()->orderBy('id')->get()->toArray()];

    $input = $capability === 'workout_exercise_add'
        ? ['workout_id' => $shown['workout_id'], 'workout_exercise_id' => $id, 'exercise_id' => actIncline(), 'remaining_weeks' => false]
        : ['workout_exercise_id' => $shown['exercises'][0]['id'], 'new_workout_exercise_id' => $id, 'exercise_id' => actIncline(), 'scope' => 'this_session'];
    actOver($user, $capability, $input)->assertNotFound();

    expect([WorkoutExercise::query()->withTrashed()->orderBy('id')->get()->toArray(), WorkoutSet::query()->orderBy('id')->get()->toArray()])->toBe($before);
})->with(['workout_exercise_add', 'exercise_swap'])->with(['theirs', 'removed']);

it('EX-008 a swap sent again after it moved every set answers as the first did', function () {
    [$user, $mesocycle, $shown] = actStarted();
    $swap = ['workout_exercise_id' => $shown['exercises'][0]['id'], 'new_workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => actIncline(), 'scope' => 'this_session'];
    actOver($user, 'exercise_swap', $swap)->assertOk();

    actOver($user, 'exercise_swap', $swap)->assertOk();

    expect(array_column(actOver($user, 'workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.exercises'), 'id'))
        ->toBe([$swap['new_workout_exercise_id']]);
});
