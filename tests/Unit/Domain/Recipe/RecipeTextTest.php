<?php

use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeText;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Recipes (RecipesModelTests, RecipeEditorModelTests): the words
// the Recipes screens show, which the server now writes (CAP-015).

it('RCP-020 a row shows the macros of one serving as whole numbers', function () {
    expect(RecipeText::macros(new MealTarget(kcal: 330.4, proteinG: 27.8, fatG: 2.6, carbsG: 46.8)))
        ->toBe('330 kcal · P 28 · F 3 · C 47');
});

it('RCP-001 the recipe screen\'s macro line shows calories, protein, fat and carbs with units', function () {
    expect(RecipeText::macros(new MealTarget(kcal: 330.4, proteinG: 27.8, fatG: 2.6, carbsG: 46.8), units: true))
        ->toBe('330 kcal · P 28 g · F 3 g · C 47 g');
});

it('RCP-001 a half rounds away from zero, as the app did', function () {
    expect(RecipeText::macros(new MealTarget(kcal: 0.5, proteinG: 1.5, fatG: 2.5, carbsG: 0)))
        ->toBe('1 kcal · P 2 · F 3 · C 0');
});

it('RCP-001 one serving is singular, and a fraction shows as typed', function (float $servings, string $text) {
    expect(RecipeText::servings($servings))->toBe($text);
})->with([
    [1, '1 serving'],
    [4, '4 servings'],
    [1.5, '1.5 servings'],
    [0.5, '0.5 servings'],
    [100, '100 servings'],
]);

it('RCP-020 a meal type shows as its title, and none as none', function () {
    expect(RecipeText::mealTypeTitle('dinner'))->toBe('Dinner')
        ->and(RecipeText::mealTypeTitle('breakfast'))->toBe('Breakfast')
        ->and(RecipeText::mealTypeTitle(null))->toBeNull();
});

it('RCP-001 FOOD-006 an amount says raw or cooked only for a food with a cooked value', function () {
    expect(RecipeText::amount(600, hasCookedValue: true, isCooked: false))->toBe('600 g raw')
        ->and(RecipeText::amount(450, hasCookedValue: true, isCooked: true))->toBe('450 g cooked')
        ->and(RecipeText::amount(240, hasCookedValue: false, isCooked: false))->toBe('240 g')
        ->and(RecipeText::amount(12.5, hasCookedValue: false, isCooked: false))->toBe('12.5 g');
});

it('RCP-020 an empty list says what to do: make one, or change the filters', function () {
    expect(RecipeText::emptyText('mine', filtered: false))->toBe('No recipes yet. Create one, or import one from a web page.')
        ->and(RecipeText::emptyText('mine', filtered: true))->toBe('No recipes match these filters.')
        ->and(RecipeText::emptyText('library', filtered: false))->toBe('No library recipes match these filters.')
        ->and(RecipeText::emptyText('library', filtered: true))->toBe('No library recipes match these filters.');
});
