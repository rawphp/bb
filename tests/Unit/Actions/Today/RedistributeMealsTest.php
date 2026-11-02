<?php

use App\Actions\Today\RedistributeMeals;
use App\Models\MealPlanningState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\SheetSeed;

// What meal_redistribute saves (DAY-002, DAY-003, DAY-004). How the share is worked out is RedistributionTest's.
// 6 AM on Wednesday 23 September: every meal is still to come.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 06:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('DAY-002 saves the chosen meals\' new targets and keeps the day\'s totals and the other meals', function () {
    $user = User::factory()->create();
    $day = SheetSeed::day($user);
    SheetSeed::checkIn(SheetSeed::LUNCH, ['kcal' => 800, 'protein_g' => 55, 'fat_g' => 25, 'carbs_g' => 90]);

    app(RedistributeMeals::class)($user, '2026-09-23', [SheetSeed::BREAKFAST, SheetSeed::DINNER]);

    $meals = collect($day->fresh()->meals)->keyBy('id');
    expect($day->fresh()->only(['kcal', 'protein_g', 'fat_g', 'carbs_g']))->toBe(['kcal' => 2600.0, 'protein_g' => 170.0, 'fat_g' => 70.0, 'carbs_g' => 320.0])
        ->and($meals->keys()->all())->toBe([SheetSeed::BREAKFAST, SheetSeed::LUNCH, SheetSeed::DINNER])
        ->and($meals[SheetSeed::LUNCH]['kcal'])->toBe(780)
        ->and($meals[SheetSeed::BREAKFAST]['protein_g'])->toEqualWithDelta(115 * 30 / 120, 0.001)
        ->and($meals[SheetSeed::DINNER]['protein_g'])->toEqualWithDelta(115 * 90 / 120, 0.001)
        ->and($meals[SheetSeed::DINNER]['time'])->toBe('19:00');
});

it('DAY-003 DAY-004 a chosen meal with foods is marked still planning; one without foods is not', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);
    SheetSeed::food(SheetSeed::BREAKFAST);

    app(RedistributeMeals::class)($user, '2026-09-23', [SheetSeed::BREAKFAST, SheetSeed::DINNER]);

    expect(MealPlanningState::query()->pluck('state', 'meal_id')->all())->toBe([SheetSeed::BREAKFAST => 'still_planning']);
});

it('DAY-002 refuses a meal that is checked in, past, or has only locked foods, and saves nothing', function (Closure $arrange, string $now, string $message) {
    Carbon::setTestNow($now);
    $user = User::factory()->create();
    $day = SheetSeed::day($user);
    $arrange();
    $before = $day->fresh()->meals;

    expect(fn () => app(RedistributeMeals::class)($user, '2026-09-23', [SheetSeed::BREAKFAST]))->toThrow(DomainException::class, $message)
        ->and($day->fresh()->meals)->toBe($before);
})->with([
    'checked in' => [fn () => SheetSeed::checkIn(SheetSeed::BREAKFAST), '2026-09-23 06:00:00', 'Breakfast can\'t take a share'],
    'past' => [fn () => null, '2026-09-23 08:00:00', 'Breakfast can\'t take a share'],
    'only locked foods' => [fn () => SheetSeed::food(SheetSeed::BREAKFAST, locked: true), '2026-09-23 06:00:00', 'Breakfast can\'t take a share'],
]);

it('DAY-002 needs at least one meal, and a day with a target', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);

    expect(fn () => app(RedistributeMeals::class)($user, '2026-09-23', []))->toThrow(DomainException::class, 'Choose at least one meal')
        ->and(fn () => app(RedistributeMeals::class)($user, '2026-09-24', [SheetSeed::BREAKFAST]))->toThrow(DomainException::class, 'no meals planned');
});

it('DAY-002 refuses a meal of another day in plain words', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);
    SheetSeed::day($user, '2026-09-24');

    expect(fn () => app(RedistributeMeals::class)($user, '2026-09-23', [SheetSeed::id(SheetSeed::DINNER, '260924')]))
        ->toThrow(DomainException::class, 'That meal can\'t take a share');
});

it('DAY-002 DAY-004 the day and the planning states are saved together: a failed state save leaves the day as it was', function () {
    $user = User::factory()->create();
    $day = SheetSeed::day($user);
    SheetSeed::food(SheetSeed::BREAKFAST);
    $before = $day->fresh()->meals;
    $armed = true;
    // Left registered and disarmed after: Event::forget would also drop the model's own listeners.
    Event::listen('eloquent.saving: '.MealPlanningState::class, function () use (&$armed) {
        if ($armed) {
            throw new RuntimeException('state save failed');
        }
    });

    try {
        expect(fn () => app(RedistributeMeals::class)($user, '2026-09-23', [SheetSeed::BREAKFAST, SheetSeed::DINNER]))
            ->toThrow(RuntimeException::class, 'state save failed');
    } finally {
        $armed = false;
    }

    expect($day->fresh()->meals)->toBe($before);
});
