<?php

use App\Models\Plan;
use App\Models\Profile;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

it('PLAN-008 the train snapshot\'s grid applies the stored changes, and its review day is the one in force today', function (string $today, int $iso) {
    Carbon::setTestNow("{$today} 12:00:00");
    // Weeks start Monday 08-31; the change makes the week of 09-28 run 10 days, so the next week starts on 10-08.
    $user = TrainSeed::user();
    Plan::query()->where('user_id', $user->id)->firstOrFail()->reviewDayChanges()->create(['effective_on' => '2026-09-28', 'review_weekday' => 4]);

    $train = TrainSnapshot::load($user);

    expect($train->grid()->start(5)->format('Y-m-d'))->toBe('2026-10-08')
        ->and($train->reviewIsoWeekday($train->grid()))->toBe($iso);

    Carbon::setTestNow();
})->with([
    'before the transition week' => ['2026-09-27', 1],
    'on the transition week\'s first day' => ['2026-09-28', 4],
    'after it' => ['2026-10-14', 4],
]);
