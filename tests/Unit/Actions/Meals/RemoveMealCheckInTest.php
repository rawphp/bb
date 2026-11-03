<?php

use App\Actions\Meals\RemoveMealCheckIn;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Meal/MealCheckInTests.swift (the tick on a checked-in meal) and
// MealMenuTests.swift (removeCheckInDeletesIt).
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('MEAL-044 removes the meal\'s check-in and leaves the others', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);
    SheetSeed::checkIn(SheetSeed::BREAKFAST);
    $lunch = SheetSeed::checkIn(SheetSeed::LUNCH);

    app(RemoveMealCheckIn::class)($user, SheetSeed::BREAKFAST);

    expect(MealCheckIn::query()->pluck('id')->all())->toBe([$lunch->getKey()]);
});

it('MEAL-044 refuses a meal that is not checked in', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);

    expect(fn () => app(RemoveMealCheckIn::class)($user, SheetSeed::BREAKFAST))->toThrow(DomainException::class, 'This meal is not checked in.');
});

it('MEAL-045 removes the check-in of a meal still being planned, whose foods miss its targets', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);
    SheetSeed::food(SheetSeed::BREAKFAST);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'still_planning']);
    SheetSeed::checkIn(SheetSeed::BREAKFAST);

    app(RemoveMealCheckIn::class)($user, SheetSeed::BREAKFAST);

    expect(MealCheckIn::query()->count())->toBe(0);
});

it('MEAL-044 never removes another user\'s check-in', function () {
    SheetSeed::day(User::factory()->create());
    SheetSeed::checkIn(SheetSeed::BREAKFAST);

    expect(fn () => app(RemoveMealCheckIn::class)(User::factory()->create(), SheetSeed::BREAKFAST))->toThrow(ModelNotFoundException::class)
        ->and(MealCheckIn::query()->count())->toBe(1);
});
