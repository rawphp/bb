<?php

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
use Tests\Agent\Any;
use Tests\Agent\Coach;
use Tests\Support\TrainSeed;

// Eval requests made in the middle of a workout (AGT-050). Today is Monday 21 September 2026, 5:40 PM: week 3 of Meso 2,
// whose Upper A (Bench Press, 3 sets) is on the sheet. The sets of a session that has not started are not rows yet.
uses()->group('warp-isolated');

beforeEach(fn () => Carbon::setTestNow('2026-09-21 17:40:00'));
afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $input */
function evalWrite(User $user, string $capability, array $input): void
{
    test()->actingAs($user)->postJson("/capabilities/{$capability}", $input, ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
}

/** @return array<string, mixed> workout_show's payload for week 3, day 1 */
function evalWorkout(User $user, Mesocycle $mesocycle): array
{
    return test()->actingAs($user)->postJson('/capabilities/workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->assertOk()->json('data');
}

/** @return array{0: User, 1: Mesocycle, 2: string} a user with Upper A of week 3 on the sheet, and its workout id */
function evalUpperA(bool $started = true): array
{
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $workout = WorkoutIds::workout((string) $mesocycle->id, 3, 1);
    if ($started) {
        evalWrite($user, 'workout_start', ['workout_id' => $workout]);
    }

    return [$user, $mesocycle, $workout];
}

it('AGT-050 "start my workout"', function () {
    [$user, $mesocycle, $workout] = evalUpperA(started: false);

    Coach::says($user, 'Start my workout', calls: [
        ['workout_start', ['workout_id' => $workout]],
    ], reply: 'Started Upper A.');

    expect(WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->where('week', 3)->where('day', 1)->value('actual_start_at'))->not->toBeNull();
});

it('AGT-050 "I did 100 for 8, 7 and 6 on bench"', function () {
    [$user, $mesocycle] = evalUpperA();
    $sets = array_column(evalWorkout($user, $mesocycle)['exercises'][0]['sets'], 'id');

    Coach::says($user, 'I did 100 for 8, 7 and 6 on bench', calls: array_map(
        fn (string $set, int $reps): array => ['workout_set_log', ['set_id' => $set, 'weight' => ['value' => 100, 'unit' => 'kg'], 'reps' => $reps, 'is_logged' => true]],
        $sets,
        [8, 7, 6],
    ), reply: 'Logged 100 kg for 8, 7 and 6 on Bench Press.');

    $logged = WorkoutSet::query()->whereIn('id', $sets)->orderBy('position')->get();
    expect($logged->pluck('actual_reps')->all())->toBe([8, 7, 6])
        ->and($logged->pluck('actual_weight_kg')->map(fn ($kg) => (float) $kg)->all())->toBe([100.0, 100.0, 100.0])
        ->and($logged->every(fn (WorkoutSet $set): bool => $set->is_logged))->toBeTrue();
});

it('AGT-050 "add a set of bench"', function () {
    [$user, $mesocycle] = evalUpperA();
    $block = evalWorkout($user, $mesocycle)['exercises'][0];

    Coach::says($user, 'Add a set of bench', calls: [
        ['workout_set_add', ['set_id' => Any::id(), 'workout_exercise_id' => $block['id'], 'after_set_id' => new Any($block['sets'][2]['id']), 'set_type' => new Any('regular_straight')]],
    ], reply: 'Added a fourth set of Bench Press.');

    expect(WorkoutSet::query()->where('workout_exercise_id', $block['id'])->count())->toBe(4);
});

it('AGT-050 "make my last bench set a myorep set"', function () {
    [$user, $mesocycle] = evalUpperA();
    $last = evalWorkout($user, $mesocycle)['exercises'][0]['sets'][2]['id'];

    Coach::says($user, 'Make my last bench set a myorep set', calls: [
        ['workout_set_type_set', ['set_id' => $last, 'set_type' => 'myorep']],
    ], reply: 'The last set of Bench Press is now a myorep set.');

    expect(WorkoutSet::query()->find($last)->type)->toBe('myorep');
});

it('AGT-050 "skip the last set of bench"', function () {
    [$user, $mesocycle] = evalUpperA();
    $last = evalWorkout($user, $mesocycle)['exercises'][0]['sets'][2]['id'];

    Coach::says($user, 'Skip the last set of bench', calls: [
        ['workout_set_skip', ['set_id' => $last, 'is_skipped' => true]],
    ], reply: 'Skipped the last set of Bench Press.');

    expect(WorkoutSet::query()->find($last)->is_skipped)->toBeTrue();
});

it('AGT-050 "note that bench felt heavy today"', function () {
    [$user, $mesocycle] = evalUpperA();
    $block = evalWorkout($user, $mesocycle)['exercises'][0]['id'];

    Coach::says($user, 'Note that bench felt heavy today', calls: [
        ['workout_exercise_note_set', ['workout_exercise_id' => $block, 'note' => new Any('Felt heavy today')]],
    ], reply: 'Noted on Bench Press.');

    expect(WorkoutExercise::query()->find($block)->note)->toBe('Felt heavy today');
});

it('AGT-050 "add lateral raises to today\'s workout"', function () {
    [$user, $mesocycle, $workout] = evalUpperA();
    $raises = Exercise::factory()->create(['name' => 'Lateral Raise', 'muscle_group' => MuscleGroup::SideDelts]);

    Coach::says($user, 'Add lateral raises to today\'s workout', calls: [
        ['workout_exercise_add', ['workout_id' => $workout, 'workout_exercise_id' => Any::id(), 'exercise_id' => (string) $raises->id, 'remaining_weeks' => new Any(false)]],
    ], reply: 'Added Lateral Raise to today\'s Upper A.');

    expect(array_column(evalWorkout($user, $mesocycle)['exercises'], 'name'))->toBe(['Bench Press', 'Lateral Raise']);
});

it('AGT-050 "put lateral raises before bench today"', function () {
    [$user, $mesocycle] = evalUpperA();
    $raises = Exercise::factory()->create(['name' => 'Lateral Raise', 'muscle_group' => MuscleGroup::SideDelts]);
    evalWrite($user, 'workout_exercise_add', ['workout_id' => WorkoutIds::workout((string) $mesocycle->id, 3, 1), 'workout_exercise_id' => strtolower((string) Str::ulid()), 'exercise_id' => (string) $raises->id, 'remaining_weeks' => false]);
    $block = evalWorkout($user, $mesocycle)['exercises'][1]['id'];

    Coach::says($user, 'Put lateral raises before bench today', calls: [
        ['workout_exercise_reorder', ['workout_exercise_id' => $block, 'position' => 1, 'remaining_weeks' => new Any(false)]],
    ], reply: 'Lateral Raise now comes first in today\'s Upper A.');

    expect(array_column(evalWorkout($user, $mesocycle)['exercises'], 'name'))->toBe(['Lateral Raise', 'Bench Press']);
});

it('AGT-050 "rename Upper A to Push day"', function () {
    [$user, $mesocycle, $workout] = evalUpperA(started: false);

    Coach::says($user, 'Rename Upper A to Push day', calls: [
        ['workout_day_relabel', ['workout_id' => $workout, 'label' => 'Push day']],
    ], reply: 'Upper A is now Push day.');

    expect(evalWorkout($user, $mesocycle)['day_label'])->toBe('Push day');
});

it('AGT-050 AGT-010 a destructive request asks first: "skip today\'s workout"', function () {
    [$user, $mesocycle, $workout] = evalUpperA(started: false);
    $skip = ['workout_skip', ['workout_id' => $workout]];

    Coach::says($user, 'Skip today\'s workout', refused: [$skip], asks: $skip,
        reply: 'That marks today\'s Upper A as skipped. To go ahead, send this line by itself:');

    expect(WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->where('is_skipped', true)->count())->toBe(0);
});

it('AGT-050 "what did I lift on bench last time?"', function () {
    [$user, $mesocycle] = evalUpperA(started: false);
    TrainSeed::trained($mesocycle, 2, 1, '2026-09-14');
    $bench = Exercise::query()->where('name', 'Bench Press')->sole();

    $said = Coach::says($user, 'What did I lift on bench last time?', calls: [
        ['exercise_history_show', ['exercise_id' => (string) $bench->id]],
    ], reply: 'Last time, on 14 September, you did 81 kg for 10 and 82 kg for 10.');

    expect($said->result('exercise_history_show')['data'])->not->toBeEmpty();
});
