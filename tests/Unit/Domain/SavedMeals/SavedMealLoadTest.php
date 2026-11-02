<?php

use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;
use App\Domain\SavedMeals\SavedMealLoad;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Meal/SavedMealTests.swift (load), whose
// MealModel+SavedMeals.swift joined a saved meal's foods to a meal and fitted it on the phone.

function savedLoadChicken(): Food
{
    return new Food(id: 'chicken', name: 'Chicken breast', kcal: 165, proteinG: 31, fatG: 3.6, carbsG: 0, cookedRatio: 0.75);
}

function savedLoadRice(): Food
{
    return new Food(id: 'rice', name: 'Rice', kcal: 130, proteinG: 2.7, fatG: 0.3, carbsG: 28);
}

function savedLoadOil(): Food
{
    return new Food(id: 'oil', name: 'Olive oil', kcal: 884, proteinG: 0, fatG: 100, carbsG: 0);
}

function savedLoadDinner(): MealTarget
{
    return new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128);
}

/**
 * @param  list<MealFood>  $foods
 * @return array<string, float> grams by entry id
 */
function savedLoadGrams(array $foods): array
{
    return array_combine(array_map(fn (MealFood $f) => $f->id, $foods), array_map(fn (MealFood $f) => $f->grams, $foods));
}

/** "Bulk dinner": chicken locked at 300 g, rice and olive oil unlocked. */
function savedLoadBulkDinner(): array
{
    return [
        new MealFood('saved:1', savedLoadChicken(), 300, isLocked: true),
        new MealFood('saved:2', savedLoadRice(), 100),
        new MealFood('saved:3', savedLoadOil(), 5),
    ];
}

it('MEAL-070 load keeps locked grams and refits the rest', function () {
    $loaded = (new SavedMealLoad(new MealFit))->load([], savedLoadBulkDinner(), savedLoadDinner());

    $expected = savedLoadGrams((new MealFit)->fit(savedLoadBulkDinner(), savedLoadDinner())->foods);
    expect(savedLoadGrams($loaded))->toBe($expected)
        // The locked chicken is exactly as saved; the rice and oil moved to this meal's targets.
        ->and($loaded[0]->grams)->toBe(300.0)
        ->and($loaded[0]->isLocked)->toBeTrue()
        ->and($loaded[1]->isLocked)->toBeFalse()
        ->and($loaded[1]->grams)->not->toBe(100.0)
        ->and(array_map(fn (MealFood $f) => $f->food->id, $loaded))->toBe(['chicken', 'rice', 'oil']);
});

it('MEAL-070 load keeps the cooked weighing of a saved food', function () {
    $saved = [
        new MealFood('saved:1', savedLoadChicken(), 200, Weighing::Cooked, isLocked: true),
        new MealFood('saved:2', savedLoadOil(), 5),
    ];

    $loaded = (new SavedMealLoad(new MealFit))->load([], $saved, savedLoadDinner());

    expect($loaded[0])->weighing->toBe(Weighing::Cooked)->grams->toBe(200.0)->isLocked->toBeTrue()
        ->and($loaded)->toHaveCount(2);
});

it('MEAL-070 load adds to the meal and skips a food already in it', function () {
    $chili = RecipeServing::food('recipe:9', 'Chili', new MealTarget(kcal: 300, proteinG: 25, fatG: 10, carbsG: 25), 1.5, isLocked: true);
    $lunch = [
        new MealFood('food:1', savedLoadChicken(), 150),
        $chili,
        new MealFood('food:2', savedLoadRice(), 80),
    ];
    $saved = [
        new MealFood('saved:1', savedLoadRice(), 300, isLocked: true),
        new MealFood('saved:2', savedLoadOil(), 5),
    ];

    $loaded = (new SavedMealLoad(new MealFit))->load($lunch, $saved, savedLoadDinner());

    // The rice was already there, so only the oil joins; the chili serving stays as it was.
    expect(array_map(fn (MealFood $f) => $f->id, $loaded))->toBe(['food:1', 'recipe:9', 'food:2', 'saved:2'])
        ->and($loaded[2]->isLocked)->toBeFalse()
        ->and($loaded[1]->grams)->toBe(15.0)
        ->and($loaded[1]->isLocked)->toBeTrue()
        ->and(savedLoadGrams($loaded))->toBe(savedLoadGrams((new MealFit)->fit([...$lunch, $saved[1]], savedLoadDinner())->foods));
});

it('MEAL-070 load joins every food of the saved meal, one it holds twice included, so no saved amount is lost', function () {
    $saved = [new MealFood('saved:1', savedLoadRice(), 100, isLocked: true), new MealFood('saved:2', savedLoadRice(), 50, isLocked: true)];

    $loaded = (new SavedMealLoad(new MealFit))->load([], $saved, savedLoadDinner());

    expect(savedLoadGrams($loaded))->toBe(['saved:1' => 100.0, 'saved:2' => 50.0]);
});

it('MEAL-070 load changes nothing when every saved food is already in the meal', function () {
    $meal = [new MealFood('food:1', savedLoadRice(), 80)];

    expect((new SavedMealLoad(new MealFit))->load($meal, [new MealFood('saved:1', savedLoadRice(), 300)], savedLoadDinner()))->toBe([]);
});

it('MEAL-070 load never leaves an amount below 1 g, so every food and recipe serving can be saved', function () {
    // A protein-only target fits the oil to nothing.
    $loaded = (new SavedMealLoad(new MealFit))->load(
        [],
        [new MealFood('saved:1', savedLoadChicken(), 100), new MealFood('saved:2', savedLoadOil(), 5)],
        new MealTarget(kcal: 165, proteinG: 31, fatG: 0, carbsG: 0),
    );

    expect(savedLoadGrams($loaded)['saved:2'])->toBe(RecipeServing::MIN_GRAMS);
});

it('MEAL-070 MEAL-005 load keeps a locked amount below 1 g exactly, in the meal and in the saved meal', function () {
    $loaded = (new SavedMealLoad(new MealFit))->load(
        [new MealFood('food:1', savedLoadOil(), 0.5, isLocked: true)],
        [new MealFood('saved:1', savedLoadChicken(), 0.4, isLocked: true), new MealFood('saved:2', savedLoadRice(), 100)],
        savedLoadDinner(),
    );

    expect(savedLoadGrams($loaded)['food:1'])->toBe(0.5)
        ->and(savedLoadGrams($loaded)['saved:1'])->toBe(0.4);
});
