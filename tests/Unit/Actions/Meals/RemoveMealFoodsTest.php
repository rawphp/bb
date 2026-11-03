<?php

use App\Actions\Meals\ClearMealFoods;
use App\Actions\Meals\RemoveMealFood;
use App\Models\DayTargetMeal;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MealBuilderSeed as Seed;

// Ported from rp-clone MealModelTests (MEAL-008), MealRecipeTests (RCP-002 delete) and MEAL-009's Remove foods.
uses(RefreshDatabase::class);

it('MEAL-008 removes one food and refits nothing', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $chicken = Seed::planFood($meal, Seed::chicken(), 150, 0);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 1);
    $stew = Seed::planRecipe($meal, Seed::stew(), 1.5, 2, locked: true);

    $changed = app(RemoveMealFood::class)($user, $meal, $rice->id);

    expect($changed)->toBeInstanceOf(DayTargetMeal::class)
        ->and(PlannedFood::query()->whereKey($rice->id)->exists())->toBeFalse()
        ->and($chicken->fresh()->grams)->toBe(150.0)
        ->and($stew->fresh()->servings)->toBe(1.5);
});

it('MEAL-008 RCP-002 removes a recipe serving and leaves the foods alone, and the last food leaves an empty meal', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0);
    $stew = Seed::planRecipe($meal, Seed::stew(), 1.5, 1);

    app(RemoveMealFood::class)($user, $meal, $stew->id);
    expect(PlannedRecipe::query()->whereKey($stew->id)->exists())->toBeFalse()
        ->and($rice->fresh()->grams)->toBe(80.0);

    app(RemoveMealFood::class)($user, $meal, $rice->id);
    expect(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(0)
        ->and(DayTargetMeal::query()->whereKey($meal)->exists())->toBeTrue();
});

it('MEAL-008 answers not found for an entry of another meal or user and an unknown one, and removes nothing', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $mine = Seed::planFood($meal, Seed::rice(), 80, 0);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);
    $theirs = Seed::dinner(User::factory()->create());
    $stranger = Seed::planFood($theirs, Seed::rice(), 50, 0);

    expect(fn () => app(RemoveMealFood::class)($user, $meal, $other->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RemoveMealFood::class)($user, $meal, $stranger->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RemoveMealFood::class)($user, $theirs, $stranger->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RemoveMealFood::class)($user, $meal, '01jbx8z6q7m0v2k3n4p5r6s7t8'))->toThrow(ModelNotFoundException::class)
        ->and(PlannedFood::query()->count())->toBe(3)
        ->and($mine->fresh())->not->toBeNull();
});

it('MEAL-009 clears every planned food of the meal and keeps the recipe servings and other meals', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    Seed::planFood($meal, Seed::rice(), 80, 0, locked: true);
    Seed::planFood($meal, Seed::chicken(), 150, 2);
    $stew = Seed::planRecipe($meal, Seed::stew(), 1.5, 1);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);

    $changed = app(ClearMealFoods::class)($user, $meal);

    expect($changed->id)->toBe($meal)
        ->and(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(0)
        ->and($stew->fresh()->servings)->toBe(1.5)
        ->and($other->fresh())->not->toBeNull();
});

it('MEAL-009 clears an empty meal and answers not found for another user\'s', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);

    expect(app(ClearMealFoods::class)($user, $meal)->id)->toBe($meal)
        ->and(fn () => app(ClearMealFoods::class)($user, Seed::dinner(User::factory()->create())))->toThrow(ModelNotFoundException::class);
});
