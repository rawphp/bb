<?php

use App\Capabilities\Nutrition\Data\FoodSearchInput;
use App\Capabilities\Reads\Pantry\PantryPayload;
use App\Capabilities\Reads\Pantry\PantrySnapshot;
use App\Domain\MealFit\CuratedGroup;
use App\Domain\MealFit\Food;
use App\Domain\Model\DietFilter;
use App\Domain\Model\MealTarget;
use App\Domain\Pantry\PantryFood;
use App\Domain\Pantry\PantryRecipe;
use App\Domain\Pantry\PantrySavedMeal;
use Tests\Support\ScreenFixture;

// What food_search shows (rp-clone PantryModel and PantryView), from in-memory rows.

/** @param  list<DietFilter>  $diets */
function payloadFood(string $id, string $name, array $nutrition = [100, 10, 2, 12], ?CuratedGroup $group = null, ?float $cookedRatio = null, ?string $brand = null, array $diets = []): PantryFood
{
    [$kcal, $protein, $fat, $carbs] = $nutrition;

    return new PantryFood(
        food: new Food(id: $id, name: $name, kcal: $kcal, proteinG: $protein, fatG: $fat, carbsG: $carbs, cookedRatio: $cookedRatio, curatedGroup: $group),
        brand: $brand,
        isCurated: $group !== null,
        diets: $diets,
    );
}

/** @return array<string, mixed> */
function pantryPayload(array $input, PantrySnapshot $snapshot): array
{
    return PantryPayload::build(FoodSearchInput::from($input), $snapshot)->toArray();
}

/**
 * @param  array<string, mixed>  $payload
 * @return list<list<string>>
 */
function sectionNames(array $payload): array
{
    return array_map(fn (array $section) => array_column($section['rows'], 'name'), $payload['sections']);
}

it('FOOD-020 FOOD-021 FOOD-022 the Curated tab shows each group with its foods, favourites starred', function () {
    $foods = [
        payloadFood('breast', 'Chicken breast', [120, 22.5, 2.6, 0], CuratedGroup::ProteinRecommended, cookedRatio: 120 / 165),
        payloadFood('whites', 'Egg whites', [52, 10.9, 0.2, 0.7], CuratedGroup::ProteinRecommended),
        ...array_map(fn (int $n) => payloadFood("p{$n}", "Protein {$n}", group: CuratedGroup::ProteinRecommended), range(1, 4)),
        payloadFood('broccoli', 'Broccoli', [34, 2.8, 0.4, 7], CuratedGroup::Veggies, cookedRatio: 34 / 35),
    ];

    $payload = pantryPayload([], new PantrySnapshot(foods: $foods, favoriteIds: ['breast']));

    assertMatchesScreenFixture('food_search', $payload);
    expect(ScreenFixture::differences(ScreenFixture::load('food_search.curated'), $payload))->toBe([]);
    expect(array_column($payload['sections'], 'id'))->toBe(['protein_recommended', 'veggies'])
        ->and(array_column($payload['sections'], 'title'))->toBe(['Protein · Recommended', 'Veggies'])
        ->and(array_column($payload['sections'], 'group'))->toBe(['protein_recommended', 'veggies'])
        ->and(array_column($payload['sections'], 'show_more_title'))->toBe(['Show 25 more', null])
        ->and(sectionNames($payload))->toBe([['Chicken breast', 'Egg whites', 'Protein 1', 'Protein 2', 'Protein 3'], ['Broccoli']])
        ->and($payload['sections'][0]['rows'][0])->toBe([
            'food_id' => 'breast',
            'name' => 'Chicken breast',
            'detail' => null,
            'raw' => ['kcal' => '120 kcal', 'protein' => 'P 22.5', 'fat' => 'F 2.6', 'carbs' => 'C 0'],
            'cooked' => ['kcal' => '165 kcal', 'protein' => 'P 30.9', 'fat' => 'F 3.6', 'carbs' => 'C 0'],
            'basis_text' => 'per 100 g',
            'is_curated' => true,
            'is_favorite' => true,
            'is_in_meal' => false,
        ])
        ->and($payload['sections'][0]['rows'][1]['cooked'])->toBeNull()
        ->and($payload['empty_text'])->toBeNull();
});

it('FOOD-021 a group opened with Show 25 more lists up to thirty', function () {
    $foods = array_map(fn (int $n) => payloadFood("v{$n}", "Veg {$n}", group: CuratedGroup::Veggies), range(1, 40));

    $payload = pantryPayload(['expanded_groups' => ['veggies']], new PantrySnapshot(foods: $foods));

    expect($payload['sections'][0]['rows'])->toHaveCount(30)
        ->and($payload['sections'][0]['show_more_title'])->toBeNull();
});

it('FOOD-020 the Recents, Favorites and Custom tabs each list their own foods in one section', function () {
    $foods = [payloadFood('oats', 'Oats'), payloadFood('banana', 'Banana')];

    foreach (['recents', 'favorites', 'custom'] as $tab) {
        $payload = pantryPayload(['tab' => $tab], new PantrySnapshot(foods: $foods));

        expect(array_column($payload['sections'], 'id'))->toBe([$tab])
            ->and($payload['sections'][0]['title'])->toBeNull()
            ->and($payload['sections'][0]['group'])->toBeNull()
            ->and(sectionNames($payload))->toBe([['Oats', 'Banana']]);
    }
});

it('FOOD-026 FOOD-027 a tab list hides the foods the group and diet filters leave out', function () {
    $foods = [
        payloadFood('yogurt', 'Yogurt', group: CuratedGroup::ProteinRecommended, diets: [DietFilter::Vegetarian]),
        payloadFood('tofu', 'Tofu', group: CuratedGroup::ProteinRecommended, diets: [DietFilter::Vegetarian, DietFilter::Vegan]),
        payloadFood('bread', 'Bread', diets: [DietFilter::Vegetarian, DietFilter::Vegan]),
    ];
    $snapshot = new PantrySnapshot(diets: [DietFilter::Vegan], foods: $foods);

    expect(sectionNames(pantryPayload(['tab' => 'recents'], $snapshot)))->toBe([['Tofu', 'Bread']])
        ->and(sectionNames(pantryPayload(['tab' => 'recents', 'group' => 'protein_recommended'], $snapshot)))->toBe([['Tofu']]);
});

it('FOOD-027 lists the seven diet filters in order and says which are on', function () {
    $payload = pantryPayload([], new PantrySnapshot(diets: [DietFilter::Vegan, DietFilter::GlutenFree]));

    expect($payload['diets'])->toBe([
        ['key' => 'dairy_free', 'title' => 'Dairy free', 'is_on' => false],
        ['key' => 'gluten_free', 'title' => 'Gluten free', 'is_on' => true],
        ['key' => 'grain_free', 'title' => 'Grain free', 'is_on' => false],
        ['key' => 'low_fodmap', 'title' => 'Low FODMAP', 'is_on' => false],
        ['key' => 'vegetarian', 'title' => 'Ovo-lacto vegetarian', 'is_on' => false],
        ['key' => 'paleo', 'title' => 'Paleo', 'is_on' => false],
        ['key' => 'vegan', 'title' => 'Vegan', 'is_on' => true],
    ]);
});

it('FOOD-023 FOOD-028 FOOD-062 a search lists local matches, then More results, marks the meal\'s foods and offers a custom food', function () {
    $found = [
        payloadFood('soup', 'Chicken noodle soup', [45, 3, 1.5, 5], brand: "Campbell's"),
        payloadFood('breast', 'Chicken breast', group: CuratedGroup::ProteinRecommended),
        payloadFood('stock', 'Chicken stock'),
        payloadFood('beef', 'Beef and chicken stew'),
    ];

    $payload = pantryPayload(
        ['query' => '  chicken ', 'tab' => 'custom', 'meal_id' => 'meal'],
        new PantrySnapshot(foods: $found, ownIds: ['soup'], favoriteIds: ['breast'], mealFoods: ['beef' => 'Beef and chicken stew', 'eye' => 'Beef eye of round']),
    );

    assertMatchesScreenFixture('food_search', $payload);
    expect(array_column($payload['sections'], 'id'))->toBe(['matches', 'more'])
        ->and(array_column($payload['sections'], 'title'))->toBe([null, 'More results'])
        ->and(sectionNames($payload))->toBe([['Chicken noodle soup', 'Chicken breast'], ['Chicken stock', 'Beef and chicken stew']])
        ->and($payload['sections'][0]['rows'][0]['detail'])->toBe("Campbell's")
        ->and($payload['sections'][0]['rows'][0]['raw'])->toBe(['kcal' => '45 kcal', 'protein' => 'P 3', 'fat' => 'F 1.5', 'carbs' => 'C 5'])
        ->and(array_column($payload['sections'][1]['rows'], 'is_in_meal'))->toBe([false, true])
        ->and($payload['already_in_meal'])->toBe([
            ['food_id' => 'beef', 'name' => 'Beef and chicken stew'],
            ['food_id' => 'eye', 'name' => 'Beef eye of round'],
        ])
        ->and($payload['create_custom_food'])->toBe(['title' => "Can't find it? Create a custom food", 'name' => 'chicken'])
        ->and($payload['suggestion'])->toBeNull()
        ->and($payload['empty_text'])->toBeNull();
});

it('FOOD-023 a search with only More results has no local section', function () {
    $payload = pantryPayload(['query' => 'stock'], new PantrySnapshot(foods: [payloadFood('stock', 'Chicken stock')]));

    expect(array_column($payload['sections'], 'id'))->toBe(['more']);
});

it('FOOD-024 a search that finds nothing offers the corrected spelling', function () {
    $payload = pantryPayload(['query' => 'chiken brest'], new PantrySnapshot(suggestion: 'chicken breast'));

    assertMatchesScreenFixture('food_search', $payload);
    expect(ScreenFixture::differences(ScreenFixture::load('food_search.did-you-mean'), $payload))->toBe([]);
    expect($payload['suggestion'])->toBe(['text' => 'Did you mean chicken breast?', 'query' => 'chicken breast'])
        ->and($payload['sections'])->toBe([])
        ->and($payload['empty_text'])->toBe('No foods match chiken brest.')
        ->and($payload['create_custom_food']['name'])->toBe('chiken brest');
});

it('FOOD-009 in ounces each row reads per 3.5 oz', function () {
    $payload = pantryPayload(['tab' => 'recents', 'unit' => 'oz'], new PantrySnapshot(foods: [payloadFood('oats', 'Oats', [389, 16.9, 6.9, 66.3])]));

    expect($payload['sections'][0]['rows'][0]['basis_text'])->toBe('per 3.5 oz')
        ->and($payload['sections'][0]['rows'][0]['raw'])->toBe(['kcal' => '386 kcal', 'protein' => 'P 16.8', 'fat' => 'F 6.8', 'carbs' => 'C 65.8']);
});

it('FOOD-020 an empty tab says so, and says when the filters hid its foods', function () {
    $empty = pantryPayload(['tab' => 'custom'], new PantrySnapshot);
    $hidden = pantryPayload(['tab' => 'recents'], new PantrySnapshot(diets: [DietFilter::Vegan], foods: [payloadFood('egg', 'Egg')]));

    assertMatchesScreenFixture('food_search', $empty);
    expect(ScreenFixture::differences(ScreenFixture::load('food_search.empty'), $empty))->toBe([]);
    expect($empty['sections'])->toBe([])
        ->and($empty['empty_text'])->toBe('No custom foods yet.')
        ->and($empty['create_custom_food'])->toBeNull()
        ->and($hidden['sections'])->toBe([])
        ->and($hidden['empty_text'])->toBe('No recent foods match your filters.')
        ->and(pantryPayload([], new PantrySnapshot)['empty_text'])->toBe('No curated foods match your filters.');
});

it('FOOD-020 the Recipes filter lists the recipes read, each with a serving\'s macros in whole numbers', function () {
    $recipes = [
        new PantryRecipe('chilli', 'Vegan chilli', 'dinner', new MealTarget(kcal: 412.4, proteinG: 21.6, fatG: 9.5, carbsG: 58.2), isLibrary: false),
        new PantryRecipe('oats', 'Overnight oats', null, new MealTarget(kcal: 330, proteinG: 28, fatG: 3, carbsG: 47), isLibrary: true),
    ];
    $snapshot = new PantrySnapshot(foods: [payloadFood('x', 'Chilli powder')], recipes: $recipes);

    $payload = pantryPayload(['collection' => 'recipes', 'query' => 'chilli'], $snapshot);

    assertMatchesScreenFixture('food_search', $payload);
    expect($payload['recipes'])->toBe([
        ['recipe_id' => 'chilli', 'name' => 'Vegan chilli', 'meal_type_title' => 'Dinner', 'macros_text' => '412 kcal · P 22 · F 10 · C 58', 'is_library' => false],
        ['recipe_id' => 'oats', 'name' => 'Overnight oats', 'meal_type_title' => null, 'macros_text' => '330 kcal · P 28 · F 3 · C 47', 'is_library' => true],
    ])
        ->and($payload['sections'])->toBe([])
        ->and($payload['saved_meals'])->toBe([])
        ->and($payload['create_custom_food'])->toBeNull()
        ->and($payload['empty_text'])->toBeNull()
        ->and(pantryPayload(['collection' => 'recipes', 'query' => 'pie'], new PantrySnapshot)['empty_text'])->toBe('No recipes match pie.')
        ->and(pantryPayload(['collection' => 'recipes'], new PantrySnapshot)['empty_text'])->toBe('No recipes yet.')
        ->and(pantryPayload(['collection' => 'recipes'], new PantrySnapshot(recipesHidden: true))['empty_text'])->toBe('No recipes match your filters.');
});

it('FOOD-020 the Saved Meals filter lists the saved meals read, with how many foods each holds', function () {
    $snapshot = new PantrySnapshot(savedMeals: [new PantrySavedMeal('bulk', 'Bulk dinner', 3), new PantrySavedMeal('shake', 'Shake', 1)]);

    expect(pantryPayload(['collection' => 'saved_meals'], $snapshot)['saved_meals'])->toBe([
        ['saved_meal_id' => 'bulk', 'name' => 'Bulk dinner', 'detail' => '3 foods'],
        ['saved_meal_id' => 'shake', 'name' => 'Shake', 'detail' => '1 food'],
    ])
        ->and(pantryPayload(['collection' => 'saved_meals', 'query' => 'pie'], new PantrySnapshot)['empty_text'])->toBe('No saved meals match pie.')
        ->and(pantryPayload(['collection' => 'saved_meals'], new PantrySnapshot)['empty_text'])->toBe('No saved meals yet.');
});
