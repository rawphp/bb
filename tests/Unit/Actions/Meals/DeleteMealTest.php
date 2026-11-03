<?php

use App\Actions\Meals\DeleteMeal;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;

// Ported from rp-clone ios/Sources/RPCore/Features/Today/TodayModel+Meals.swift (deleteMeal) and MealMenuTests.swift
// (deleteMealAsksFirstThenSavesTheDayTargetWithoutIt). The confirm is the client's; the agent's is AGT-010's.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('MEAL-009 deletes the meal from its day with its foods, recipes, check-in and planning state, and keeps the others', function () {
    $user = User::factory()->create();
    $day = SheetSeed::day($user);
    SheetSeed::food(SheetSeed::LUNCH);
    PlannedRecipe::factory()->create(['meal_id' => SheetSeed::LUNCH]);
    SheetSeed::checkIn(SheetSeed::LUNCH);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::LUNCH, 'state' => 'still_planning']);
    $kept = SheetSeed::food(SheetSeed::DINNER);

    $meals = app(DeleteMeal::class)($user, SheetSeed::LUNCH);

    expect(array_column($day->fresh()->meals, 'id'))->toBe([SheetSeed::BREAKFAST, SheetSeed::DINNER])
        ->and(DayTargetMeal::query()->orderBy('position')->pluck('position', 'id')->all())->toBe([SheetSeed::BREAKFAST => 0, SheetSeed::DINNER => 1])
        ->and(PlannedFood::query()->pluck('id')->all())->toBe([$kept->getKey()])
        ->and(PlannedRecipe::query()->count())->toBe(0)
        ->and(MealCheckIn::query()->count())->toBe(0)
        ->and(MealPlanningState::query()->count())->toBe(0)
        ->and(array_map(fn (DayTargetMeal $meal) => $meal->getKey(), $meals))->toBe([SheetSeed::BREAKFAST, SheetSeed::LUNCH, SheetSeed::DINNER]);
});

it('MEAL-009 deletes a meal the client added offline under its ULID', function () {
    $user = User::factory()->create();
    $day = SheetSeed::day($user);
    $day->update(['meals' => [...$day->meals, ['id' => SheetSeed::CLIENT_MEAL, 'name' => 'Extra meal', 'time' => '15:00', 'kcal' => 0, 'protein_g' => 0, 'fat_g' => 0, 'carbs_g' => 0]]]);

    app(DeleteMeal::class)($user, strtolower(SheetSeed::CLIENT_MEAL));

    expect(array_column($day->fresh()->meals, 'id'))->toBe([SheetSeed::BREAKFAST, SheetSeed::LUNCH, SheetSeed::DINNER]);
});

it('MEAL-009 never deletes another user\'s meal', function () {
    SheetSeed::day(User::factory()->create());

    expect(fn () => app(DeleteMeal::class)(User::factory()->create(), SheetSeed::LUNCH))->toThrow(ModelNotFoundException::class)
        ->and(DayTarget::query()->sole()->meals)->toHaveCount(3);
});
