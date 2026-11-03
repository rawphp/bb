<?php

use App\Actions\Meals\StepMealFood;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MealBuilderSeed as Seed;

// Ported from rp-clone MealModelTests (MEAL-004) and MealRecipeTests (RCP-002 + and −), on the database.
uses(RefreshDatabase::class);

it('MEAL-004 adds five grams to a food with up and rebalances the other unlocked foods', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $chicken = Seed::planFood($meal, Seed::chicken(), 150, 0);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 1);
    $oil = Seed::planFood($meal, Seed::oil(), 20, 2, locked: true);

    app(StepMealFood::class)($user, $meal, $chicken->id, 'up');

    expect($chicken->fresh()->grams)->toBe(155.0)
        ->and($rice->fresh()->grams)->not->toBe(80.0)
        ->and($oil->fresh()->grams)->toBe(20.0);
});

it('MEAL-004 takes five grams off with down, never below zero, and saves a zero as 1 g', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0);
    $chicken = Seed::planFood($meal, Seed::chicken(), 150, 1, locked: true);

    app(StepMealFood::class)($user, $meal, $rice->id, 'down');
    expect($rice->fresh()->grams)->toBe(75.0);

    $rice->update(['grams' => 3.0]);
    app(StepMealFood::class)($user, $meal, $rice->id, 'down');
    expect($rice->fresh()->grams)->toBe(1.0)
        ->and($chicken->fresh()->grams)->toBe(150.0);
});

it('RCP-002 adds half a serving with up and takes half off with down, never below a tenth', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 100, 0);
    $serving = Seed::planRecipe($meal, Seed::stew(), 1, 1);

    app(StepMealFood::class)($user, $meal, $serving->id, 'up');
    expect($serving->fresh()->servings)->toBe(1.5)
        ->and($rice->fresh()->grams)->not->toBe(100.0);

    app(StepMealFood::class)($user, $meal, $serving->id, 'down');
    expect($serving->fresh()->servings)->toBe(1.0);

    $serving->update(['servings' => 0.3]);
    app(StepMealFood::class)($user, $meal, $serving->id, 'down');
    expect($serving->fresh()->servings)->toBe(0.1);

    $rice->update(['grams' => 77.0]);
    app(StepMealFood::class)($user, $meal, $serving->id, 'down');
    expect($serving->fresh()->servings)->toBe(0.1)
        ->and($rice->fresh()->grams)->toBe(77.0);
});

it('MEAL-004 answers not found for another meal\'s entry and another user\'s meal', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);
    $theirs = Seed::dinner(User::factory()->create());

    expect(fn () => app(StepMealFood::class)($user, $meal, $other->id, 'up'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(StepMealFood::class)($user, $theirs, $other->id, 'up'))->toThrow(ModelNotFoundException::class)
        ->and($other->fresh()->grams)->toBe(50.0);
});
