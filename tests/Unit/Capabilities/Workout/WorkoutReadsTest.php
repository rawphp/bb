<?php

use App\Capabilities\Workout\WorkoutReads;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The reads a workout write changes (CAP-011). The keys of a session with its mesocycle are proven at the HTTP seam
// (WorkoutSetWritesTest); this covers a session whose mesocycle is unset or gone, which has no calendar to read.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('CAP-011 a session with no mesocycle names only its workout and its own days', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();

    $unset = WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => null, 'week' => 3, 'day' => 1,
        'planned_start_at' => '2026-09-21 07:00:00', 'planned_end_at' => '2026-09-21 08:00:00', 'actual_start_at' => '2026-09-22 09:00:00',
    ]);
    $mesocycle = TrainSeed::mesocycle($user);
    $gone = WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 2, 'day' => 2,
        'planned_start_at' => '2026-09-18 07:00:00', 'planned_end_at' => '2026-09-18 08:00:00', 'actual_start_at' => '2026-09-18 07:05:00',
    ]);
    $mesocycle->delete();

    expect(WorkoutReads::keys($user, $unset))->toBe([
        'workout_show{"day":1,"mesocycle_id":"","week":3}',
        'today_show{"date":"2026-09-21"}',
        'today_show{"date":"2026-09-22"}',
        'today_show{}',
    ])->and(WorkoutReads::keys($user, $gone->refresh()))->toBe([
        "workout_show{\"day\":2,\"mesocycle_id\":\"{$mesocycle->id}\",\"week\":2}",
        'today_show{"date":"2026-09-18"}',
    ]);
});
