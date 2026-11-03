<?php

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\Plan;
use App\Models\PlanWeek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// SCH-063 through the HTTP seam: a session logged in a rough week raises nothing at its next occurrence. The rules are
// ProgressionApplyTest and SetPlanTest.

afterEach(fn () => Carbon::setTestNow());

it('SCH-063 a session logged in a rough week keeps next week\'s targets and sets where it ran', function (bool $rough, int $sets, array $targets, string $toast) {
    Carbon::setTestNow('2026-09-07 19:00:00');
    Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 30,
    ]);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-07', 'is_rough' => $rough]);
    // Week 1: 3 sets of Bench Press at 80 kg for 10, and chest asks for more (PROG-010 would add 2 sets).
    $session = WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00');
    WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), ['pump' => 'low', 'volume' => 'notEnough']);

    $said = test()->actingAs($user)->postJson('/capabilities/workout_finish', ['workout_id' => WorkoutSeed::id($session)], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()->json('data.toast');
    $data = test()->actingAs($user)->postJson('/capabilities/workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 2, 'day' => 1])
        ->assertOk()->json('data');

    expect($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe($sets)
        ->and((string) $said)->toBe($toast)
        ->and(array_map(fn (array $set) => [$set['target_weight']['value'], $set['reps']], $data['exercises'][0]['sets']))->toEqual($targets);
})->with([
    // Week 2 repeats week 1's logged sets: 80 kg for 10, three times.
    'rough' => [true, 3, [[80, 10], [80, 10], [80, 10]], ''],
    // The same week not marked rough, the control: 2% more weight and 2 more sets, each 2 reps fewer (PROG-010, PROG-021, PROG-026).
    'not rough' => [false, 5, [[82.5, 10], [82.5, 9], [82.5, 8], [82.5, 6], [82.5, 4]], 'Chest +2 sets next week'],
]);

/**
 * SCH-063 with the rough week marked through rough_week_set on the day the session is logged, in plans where the
 * mesocycle's weeks do not sit one plan week after another: a paused away week between them (SCH-060), and a plan
 * that began mid-week, whose first plan week is short and stored at the plan start.
 */
it('SCH-063 SCH-060 the rough week is the plan week that held the logged session', function (string $scenario, bool $rough, int $sets, array $targets) {
    $plan = [
        'paused, rough week 1' => ['plan' => '2026-08-31', 'review' => 1, 'start' => '2026-09-07', 'pause' => '2026-09-14', 'week' => 1, 'at' => ['2026-09-07'], 'strip' => '2026-09-07'],
        'paused, rough week 2' => ['plan' => '2026-08-31', 'review' => 1, 'start' => '2026-09-07', 'pause' => '2026-09-14', 'week' => 2, 'at' => ['2026-09-07', '2026-09-21'], 'strip' => '2026-09-21'],
        'mid-week plan start' => ['plan' => '2026-08-31', 'review' => 4, 'start' => '2026-08-31', 'pause' => null, 'week' => 1, 'at' => ['2026-08-31'], 'strip' => '2026-08-27'],
    ][$scenario];
    Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 30,
    ]);
    $user = TrainSeed::user();
    Plan::query()->where('user_id', $user->id)->update(['start_date' => $plan['plan'], 'review_weekday' => $plan['review']]);
    $mesocycle = TrainSeed::mesocycle($user, ['start_date' => $plan['start'], 'phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    if ($plan['pause'] !== null) {
        PlanWeek::factory()->for($user)->create(['start_date' => $plan['pause'], 'is_deload' => false, 'is_away' => true, 'away_training' => 'pause']);
    }
    $post = fn (string $capability, array $input) => test()->actingAs($user)->postJson("/capabilities/{$capability}", $input, ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    // Each week of the mesocycle up to $plan['week'] logs Bench Press 80 kg for 10, three times, on its day.
    foreach ($plan['at'] as $index => $day) {
        Carbon::setTestNow("{$day} 19:00:00");
        $session = WorkoutSeed::started($mesocycle, $index + 1, 1, "{$day} 17:30:00");
    }
    if ($rough) {
        $post('rough_week_set', ['week_start' => $plan['strip'], 'rough' => true]);
    }
    // A later week also asks how chest healed (FB-007).
    WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), ['pump' => 'low', 'volume' => 'notEnough']
        + ($plan['week'] > 1 ? ['soreness' => 'neverGotSore'] : []));
    $post('workout_finish', ['workout_id' => WorkoutSeed::id($session)]);
    $next = $post('workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => $plan['week'] + 1, 'day' => 1])->json('data');

    // Week 1 still shows: it has no week before it to read.
    $post('workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 1, 'day' => 1]);
    expect($mesocycle->fresh()->dayRows()[0]['slots'][0]['sets'])->toBe($sets)
        ->and(array_map(fn (array $set) => [$set['target_weight']['value'], $set['reps']], $next['exercises'][0]['sets']))->toEqual($targets);
})->with(['paused, rough week 1', 'paused, rough week 2', 'mid-week plan start'])->with([
    'rough' => [true, 3, [[80, 10], [80, 10], [80, 10]]],
    'not rough' => [false, 5, [[82.5, 10], [82.5, 9], [82.5, 8], [82.5, 6], [82.5, 4]]],
]);
