<?php

use App\Capabilities\Workout\WorkoutDayReads;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrainSeed;

// The reads a day change names (CAP-011). Which keys they are is proven at the HTTP seam (SessionActionsTest); this
// covers what the change reads to name them.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('CAP-011 a day change reads the mesocycle, plan and profile once, whatever the weeks it refreshes', function () {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['weeks' => 5]);
    $session = WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 1, 'day' => 1]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $keys = WorkoutDayReads::keys($user, $session, 1);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $selects = fn (string $table) => count(array_filter(
        $log,
        fn (array $query) => preg_match('/^select\b.*\bfrom [`"]?'.$table.'[`"]?(\s|$)/i', $query['query']) === 1,
    ));
    expect($selects('mesocycles'))->toBe(1)
        ->and($selects('plans'))->toBe(1)
        ->and($selects('profiles'))->toBe(1);
    foreach (range(1, 5) as $week) {
        expect($keys)->toContain("workout_show{\"day\":1,\"mesocycle_id\":\"{$mesocycle->id}\",\"week\":{$week}}");
    }
    expect($keys)->not->toContain("workout_show{\"day\":2,\"mesocycle_id\":\"{$mesocycle->id}\",\"week\":1}");
});
