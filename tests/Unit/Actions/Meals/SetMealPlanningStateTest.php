<?php

use App\Actions\Meals\SetMealPlanningState;
use App\Models\MealPlanningState;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;

// Ported from rp-clone ios/Sources/RPCore/Features/Today/TodayModel+Redistribute.swift (togglePlanningState) and the
// Still planning case of MealMenuTests.swift. The client sends the state it wants, so a replay sets it, not flips it.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function planningStates(): array
{
    return MealPlanningState::query()->pluck('state', 'meal_id')->all();
}

it('DAY-004 marks a meal still being planned as done planning', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'still_planning']);

    app(SetMealPlanningState::class)($user, SheetSeed::BREAKFAST, true);

    expect(planningStates())->toBe([SheetSeed::BREAKFAST => 'done_planning']);
});

it('DAY-004 marks a meal with no planning state as still planning, and back to done', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);

    app(SetMealPlanningState::class)($user, SheetSeed::LUNCH, false);
    $still = planningStates();
    app(SetMealPlanningState::class)($user, SheetSeed::LUNCH, true);

    expect($still)->toBe([SheetSeed::LUNCH => 'still_planning'])
        ->and(planningStates())->toBe([SheetSeed::LUNCH => 'done_planning'])
        ->and(MealPlanningState::query()->sole()->user_id)->toBe($user->id);
});

it('DAY-004 setting the state a meal already has changes nothing', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);

    app(SetMealPlanningState::class)($user, SheetSeed::LUNCH, true);
    app(SetMealPlanningState::class)($user, SheetSeed::LUNCH, true);

    expect(planningStates())->toBe([SheetSeed::LUNCH => 'done_planning']);
});

it('DAY-004 never changes another user\'s meal', function () {
    SheetSeed::day(User::factory()->create());

    expect(fn () => app(SetMealPlanningState::class)(User::factory()->create(), SheetSeed::LUNCH, true))->toThrow(ModelNotFoundException::class)
        ->and(planningStates())->toBe([]);
});
