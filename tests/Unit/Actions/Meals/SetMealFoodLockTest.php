<?php

use App\Actions\Meals\AddMealFoods;
use App\Actions\Meals\RebalanceMeal;
use App\Actions\Meals\SetMealFoodAmount;
use App\Actions\Meals\SetMealFoodLock;
use App\Capabilities\Nutrition\Data\MealFoodAddEntry;
use App\Models\PlannedFood;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MealBuilderSeed as Seed;

// Ported from rp-clone MealModelTests (MEAL-005) and MealRecipeTests (RCP-002 locks), on the database.
uses(RefreshDatabase::class);

it('MEAL-005 locks a food and changes no amount', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $chicken = Seed::planFood($meal, Seed::chicken(), 150, 0);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 1);
    $stew = Seed::planRecipe($meal, Seed::stew(), 1.5, 2);

    app(SetMealFoodLock::class)($user, $meal, $rice->id, true);

    expect($rice->fresh()->is_locked)->toBeTrue()
        ->and($rice->fresh()->grams)->toBe(80.0)
        ->and($chicken->fresh()->grams)->toBe(150.0)
        ->and($chicken->fresh()->is_locked)->toBeFalse()
        ->and($stew->fresh()->servings)->toBe(1.5);
});

it('MEAL-005 unlocks a food, and RCP-002 locks and unlocks a recipe serving', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0, locked: true);
    $stew = Seed::planRecipe($meal, Seed::stew(), 0.4, 1);

    app(SetMealFoodLock::class)($user, $meal, $rice->id, false);
    app(SetMealFoodLock::class)($user, $meal, $stew->id, true);

    expect($rice->fresh()->is_locked)->toBeFalse()
        ->and($stew->fresh()->is_locked)->toBeTrue()
        ->and($stew->fresh()->servings)->toBe(0.4);

    app(SetMealFoodLock::class)($user, $meal, $stew->id, false);
    expect($stew->fresh()->is_locked)->toBeFalse();
});

it('MEAL-005 keeps a locked food at its exact amount through another food\'s edit, a rebalance and foods joining', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $chicken = Seed::planFood($meal, Seed::chicken(), 150, 0);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 1);
    $oil = Seed::planFood($meal, Seed::oil(), 20, 2);
    $serving = Seed::planRecipe($meal, Seed::stew(), 0.7, 3);
    app(SetMealFoodLock::class)($user, $meal, $oil->id, true);
    app(SetMealFoodLock::class)($user, $meal, $serving->id, true);

    app(SetMealFoodAmount::class)($user, $meal, $rice->id, 200, 'g');
    app(RebalanceMeal::class)($user, $meal);
    app(AddMealFoods::class)($user, $meal, [new MealFoodAddEntry(id: strtolower((string) Str::ulid()), food_id: Seed::chicken()->id)]);

    expect($oil->fresh()->grams)->toBe(20.0)
        ->and($serving->fresh()->servings)->toBe(0.7)
        ->and($chicken->fresh()->grams)->not->toBe(150.0)
        ->and(PlannedFood::query()->whereKey($oil->id)->value('is_locked'))->toBeTrue();
});

it('MEAL-005 answers not found for another meal\'s entry', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);

    expect(fn () => app(SetMealFoodLock::class)($user, $meal, $other->id, true))->toThrow(ModelNotFoundException::class)
        ->and($other->fresh()->is_locked)->toBeFalse();
});
