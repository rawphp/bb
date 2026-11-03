<?php

use App\Models\Plan;
use App\Models\Profile;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\TrainSeed;

// The light snapshot the workout reads and exercise_show build: today and the mesocycles, with the plan and profile
// when the caller has read them, and nothing else.
uses(RefreshDatabase::class);

it('MESO-001 calendarOnly holds the given today, mesocycles, plan and profile and nothing else', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $plan = Plan::query()->where('user_id', $user->id)->firstOrFail();
    $profile = Profile::query()->where('user_id', $user->id)->firstOrFail();
    $today = TrainSnapshot::day('2026-09-22');
    $mesocycles = new Collection([$mesocycle]);

    $full = TrainSnapshot::calendarOnly($today, $mesocycles, $plan, $profile);
    $bare = TrainSnapshot::calendarOnly($today, $mesocycles);

    expect($full->today)->toBe($today)
        ->and($full->mesocycles)->toBe($mesocycles)
        ->and($full->plan)->toBe($plan)
        ->and($full->profile)->toBe($profile)
        ->and($bare->plan)->toBeNull()
        ->and($bare->profile)->toBeNull();
    foreach ([$full, $bare] as $snapshot) {
        expect($snapshot->phases)->toBeEmpty()
            ->and($snapshot->sessions)->toBeEmpty()
            ->and($snapshot->sets)->toBeEmpty()
            ->and($snapshot->feedback)->toBeEmpty()
            ->and($snapshot->planWeeks)->toBeEmpty()
            ->and($snapshot->current()?->id)->toBe($mesocycle->id);
    }
});
