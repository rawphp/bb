<?php

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
