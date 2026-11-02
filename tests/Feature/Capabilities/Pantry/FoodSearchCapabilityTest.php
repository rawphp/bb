<?php

use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\FoodRecent;
use App\Models\PlannedFood;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;

// The HTTP seam of food_search, its screen fixtures, and what it reads from the database.
// The rules are unit tests (PantryTest, PantryPayloadTest); search ranking is FoodSearchTest.

beforeEach(fn () => Carbon::setTestNow('2026-11-03 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function pantryCurated(string $name, string $group, array $attributes = []): Food
{
    return Food::factory()->create(['name' => $name, 'source' => 'curated', 'curated' => true, 'curated_group' => $group] + $attributes);
}

function pantrySearch(?User $user, array $input = []): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/food_search', $input);
}

it('FOOD-020 refuses food_search when signed out', function () {
    pantrySearch(null)->assertUnauthorized();
});

it('FOOD-028 answers 404 for another user\'s meal', function () {
    $theirs = DayTargetMeal::factory()->create();

    pantrySearch(User::factory()->create(), ['meal_id' => $theirs->id])->assertNotFound();
});

it('FOOD-020 answers 422 naming the field for an unknown tab', function () {
    pantrySearch(User::factory()->create(), ['tab' => 'pantry'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'tab');
});

it('FOOD-020 FOOD-021 FOOD-027 shows the Curated tab under the user\'s diet filters, favourites starred', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['diet_filters' => ['gluten_free']]);
    $breast = pantryCurated('Chicken breast', 'protein_recommended', ['kcal' => 120, 'protein' => 22.5, 'fat' => 2.6, 'carbs' => 0, 'cooked_ratio' => 120 / 165, 'gluten_free' => true]);
    foreach (range(1, 5) as $n) {
        pantryCurated("Egg whites {$n}", 'protein_recommended', ['gluten_free' => true]);
    }
    pantryCurated('Broccoli', 'veggies', ['gluten_free' => true]);
    pantryCurated('Bread', 'carbs_recommended');
    FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $breast->id]);

    $data = pantrySearch($user)->assertOk()->json('data');

    assertMatchesScreenFixture('food_search.curated', $data);
    expect(array_column($data['sections'], 'title'))->toBe(['Protein · Recommended', 'Veggies'])
        ->and(array_column($data['sections'][0]['rows'], 'name'))->toBe(['Chicken breast', 'Egg whites 1', 'Egg whites 2', 'Egg whites 3', 'Egg whites 4'])
        ->and($data['sections'][0]['show_more_title'])->toBe('Show 25 more')
        ->and($data['sections'][0]['rows'][0]['is_favorite'])->toBeTrue()
        ->and($data['sections'][0]['rows'][0]['cooked'])->toBe(['kcal' => '165 kcal', 'protein' => 'P 30.9', 'fat' => 'F 3.6', 'carbs' => 'C 0'])
        ->and(array_column(array_filter($data['diets'], fn (array $d) => $d['is_on']), 'key'))->toBe(['gluten_free']);
});

it('FOOD-024 offers the corrected spelling when a search finds nothing', function () {
    $user = User::factory()->create();
    pantryCurated('Chicken breast', 'protein_recommended');

    $data = pantrySearch($user, ['query' => 'chiken brest'])->assertOk()->json('data');

    assertMatchesScreenFixture('food_search.did-you-mean', $data);
    expect($data['suggestion'])->toBe(['text' => 'Did you mean chicken breast?', 'query' => 'chicken breast'])
        ->and($data['empty_text'])->toBe('No foods match chiken brest.');
});

it('FOOD-020 shows an empty Custom tab', function () {
    $data = pantrySearch(User::factory()->create(), ['tab' => 'custom'])->assertOk()->json('data');

    assertMatchesScreenFixture('food_search.empty', $data);
    expect($data)->toBe(ScreenFixture::load('food_search.empty'));
});

it('FOOD-020 lists the user\'s recents newest first, favourites and custom foods by name, never another user\'s custom food', function () {
    $user = User::factory()->create();
    $oats = Food::factory()->create(['name' => 'Oats']);
    $banana = Food::factory()->create(['name' => 'Banana']);
    $theirs = Food::factory()->custom()->create(['name' => 'Their shake']);
    FoodRecent::factory()->create(['user_id' => $user->id, 'food_id' => $banana->id, 'used_at' => '2026-11-01 08:00:00']);
    FoodRecent::factory()->create(['user_id' => $user->id, 'food_id' => $oats->id, 'used_at' => '2026-11-02 08:00:00']);
    FoodRecent::factory()->create(['user_id' => $user->id, 'food_id' => $theirs->id, 'used_at' => '2026-11-03 08:00:00']);
    FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $oats->id]);
    FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $banana->id]);
    Food::factory()->custom($user)->create(['name' => 'My shake']);
    Food::factory()->custom($user)->create(['name' => 'My bar']);

    $names = fn (string $tab) => array_column(pantrySearch($user, ['tab' => $tab])->json('data.sections.0.rows'), 'name');

    expect($names('recents'))->toBe(['Oats', 'Banana'])
        ->and($names('favorites'))->toBe(['Banana', 'Oats'])
        ->and($names('custom'))->toBe(['My bar', 'My shake']);
});

it('FOOD-028 marks the foods already in the meal', function () {
    $user = User::factory()->create();
    $meal = DayTargetMeal::factory()->create(['day_target_id' => DayTarget::factory()->for($user)->state(['meals' => []])]);
    $beef = pantryCurated('Beef eye of round', 'protein_recommended');
    pantryCurated('Chicken breast', 'protein_recommended');
    PlannedFood::factory()->create(['meal_id' => $meal->id, 'food_id' => $beef->id]);

    $data = pantrySearch($user, ['meal_id' => $meal->id])->assertOk()->json('data');

    expect($data['already_in_meal'])->toBe([['food_id' => $beef->id, 'name' => 'Beef eye of round']])
        ->and(array_column($data['sections'][0]['rows'], 'is_in_meal', 'name'))->toBe(['Beef eye of round' => true, 'Chicken breast' => false]);
});

it('FOOD-020 the Recipes filter lists the user\'s own recipes, then the library\'s, with a serving\'s macros', function () {
    $user = User::factory()->create();
    $oats = Food::factory()->create(['kcal' => 400, 'protein' => 10, 'fat' => 8, 'carbs' => 60]);
    $mine = Recipe::factory()->for($user)->create(['name' => 'Zesty bowl', 'servings' => 2, 'meal_type' => 'lunch']);
    RecipeIngredient::factory()->create(['recipe_id' => $mine->id, 'food_id' => $oats->id, 'grams' => 100]);
    Recipe::factory()->create(['name' => 'Burrito', 'meal_type' => 'dinner']);
    Recipe::factory()->for(User::factory()->create())->create(['name' => 'Their recipe']);

    $recipes = pantrySearch($user, ['collection' => 'recipes'])->assertOk()->json('data.recipes');

    expect(array_column($recipes, 'name'))->toBe(['Zesty bowl', 'Burrito'])
        ->and(array_column($recipes, 'is_library'))->toBe([false, true])
        ->and($recipes[0]['macros_text'])->toBe('200 kcal · P 5 · F 4 · C 30')
        ->and($recipes[0]['meal_type_title'])->toBe('Lunch');
});

it('FOOD-020 the Saved Meals filter lists the user\'s saved meals by name with their food counts', function () {
    $user = User::factory()->create();
    $dinner = SavedMeal::factory()->for($user)->create(['name' => 'Bulk dinner']);
    SavedMealFood::factory()->count(3)->sequence(fn ($s) => ['position' => $s->index])->create(['saved_meal_id' => $dinner->id]);
    SavedMeal::factory()->for($user)->create(['name' => 'Breakfast']);
    SavedMeal::factory()->for(User::factory()->create())->create(['name' => 'Their meal']);

    $meals = pantrySearch($user, ['collection' => 'saved_meals'])->assertOk()->json('data.saved_meals');

    expect(array_column($meals, 'detail', 'name'))->toBe(['Breakfast' => '0 foods', 'Bulk dinner' => '3 foods']);
});
