<?php

use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeMacros;
use App\Domain\Recipe\RecipeServing;

// Ported from rp-clone ios/Tests/RPCoreTests/Domain/Recipe/RecipeMacrosTests.swift. Nutrients per 100 g raw.

function recipeChicken(): Food
{
    return new Food(id: 'chicken', name: 'Chicken', kcal: 120, proteinG: 23, fatG: 2, carbsG: 0, cookedRatio: 0.75);
}

function recipeRice(): Food
{
    return new Food(id: 'rice', name: 'Rice', kcal: 350, proteinG: 8, fatG: 1, carbsG: 78);
}

function recipePerServing(): MealTarget
{
    return new MealTarget(kcal: 400, proteinG: 30, fatG: 10, carbsG: 45);
}

function recipeFitTarget(): MealTarget
{
    return new MealTarget(kcal: 900, proteinG: 70, fatG: 25, carbsG: 90);
}

function recipeClose(MealTarget $actual, MealTarget $expected): void
{
    expect($actual->kcal)->toEqualWithDelta($expected->kcal, 1e-9)
        ->and($actual->proteinG)->toEqualWithDelta($expected->proteinG, 1e-9)
        ->and($actual->fatG)->toEqualWithDelta($expected->fatG, 1e-9)
        ->and($actual->carbsG)->toEqualWithDelta($expected->carbsG, 1e-9);
}

// per serving

test('RCP-002 a serving is the ingredients per 100 g sum over the servings', function () {
    $macros = RecipeMacros::perServing(
        [new MealFood('c', recipeChicken(), 200), new MealFood('r', recipeRice(), 100)], servings: 2,
    );

    // (240 + 350) / 2 kcal, (46 + 8) / 2 P, (4 + 1) / 2 F, 78 / 2 C
    recipeClose($macros, new MealTarget(kcal: 295, proteinG: 27, fatG: 2.5, carbsG: 39));
});

test('RCP-002 a cooked ingredient counts its raw grams', function () {
    // 150 g cooked at 0.75 cooked grams per raw gram is 200 g raw.
    $macros = RecipeMacros::perServing([new MealFood('c', recipeChicken(), 150, Weighing::Cooked)], servings: 1);

    recipeClose($macros, new MealTarget(kcal: 240, proteinG: 46, fatG: 4, carbsG: 0));
});

test('RCP-002 a cooked ingredient without a ratio counts its grams as they are', function () {
    $macros = RecipeMacros::perServing([new MealFood('r', recipeRice(), 100, Weighing::Cooked)], servings: 1);

    recipeClose($macros, new MealTarget(kcal: 350, proteinG: 8, fatG: 1, carbsG: 78));
});

test('RCP-002 a recipe with no servings has no macros', function () {
    $macros = RecipeMacros::perServing([new MealFood('r', recipeRice(), 100)], servings: 0);

    expect($macros)->toEqual(new MealTarget(kcal: 0, proteinG: 0, fatG: 0, carbsG: 0));
});

test('RCP-002 a recipe with no ingredients has no macros', function () {
    expect(RecipeMacros::perServing([], servings: 4))->toEqual(new MealTarget(kcal: 0, proteinG: 0, fatG: 0, carbsG: 0));
});

// a serving in the fit

test('RCP-002 a serving is a food of ten grams per serving with the serving\'s macros', function () {
    $serving = RecipeServing::food('chili', 'Chili', recipePerServing(), servings: 1.5);

    expect($serving->food->name)->toBe('Chili')
        ->and($serving->grams)->toBe(15.0);
    recipeClose($serving->macros(), new MealTarget(kcal: 600, proteinG: 45, fatG: 15, carbsG: 67.5));
    expect(RecipeServing::servings($serving))->toBe(1.5);
});

test('RCP-002 a locked serving is locked like a food', function () {
    $locked = RecipeServing::food('chili', 'Chili', recipePerServing(), servings: 2, isLocked: true);

    expect($locked->isLocked)->toBeTrue();
});

test('RCP-002 servings read back exactly at every tenth', function () {
    foreach (range(1, 100) as $tenths) {
        $servings = $tenths / 10.0;
        $serving = RecipeServing::food('chili', 'Chili', recipePerServing(), $servings);

        expect(RecipeServing::servings($serving))->toBe($servings, (string) $servings);
    }
});

test('RCP-002 the fit scales a serving with the foods and lands on tenths', function () {
    $chili = RecipeServing::food('chili', 'Chili', recipePerServing(), servings: 0);
    $foods = [$chili, new MealFood('r', recipeRice(), 0), new MealFood('c', recipeChicken(), 0)];

    $result = (new MealFit)->fit($foods, recipeFitTarget());

    $servings = RecipeServing::servings($result->foods[0]);
    expect($servings)->toBeGreaterThan(0)
        ->and($servings * 10)->toEqualWithDelta(round($servings * 10), 1e-9)
        ->and($result->targetsMet)->toBeTrue();
});

test('RCP-002 a locked serving keeps its servings through the fit', function () {
    $chili = RecipeServing::food('chili', 'Chili', recipePerServing(), servings: 1.5, isLocked: true);

    $result = (new MealFit)->fit(
        [$chili, new MealFood('r', recipeRice(), 0), new MealFood('c', recipeChicken(), 0)], recipeFitTarget(),
    );

    expect(RecipeServing::servings($result->foods[0]))->toBe(1.5)
        ->and($result->foods[0]->isLocked)->toBeTrue();
});
