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
use Illuminate\Support\Facades\Event;
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

/**
 * How many foods one food_search hydrates: the Pantry loads only the rows it shows.
 *
 * @param  array<string, mixed>  $input
 */
function foodsLoaded(User $user, array $input): int
{
    $loaded = 0;
    $event = 'eloquent.retrieved: '.Food::class;
    Event::listen($event, function () use (&$loaded) {
        $loaded++;
    });
    try {
        pantrySearch($user, $input)->assertOk();
    } finally {
        Event::forget($event);
    }

    return $loaded;
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
    $broccoli = pantryCurated('Broccoli', 'veggies', ['gluten_free' => true]);
    pantryCurated('Bread', 'carbs_recommended');
    FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $breast->id]);
    FoodFavorite::factory()->create(['user_id' => User::factory()->create()->id, 'food_id' => $broccoli->id]);

    $data = pantrySearch($user)->assertOk()->json('data');

    assertMatchesScreenFixture('food_search', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('food_search.curated'), $data))->toBe([]);
    expect(array_column($data['sections'], 'title'))->toBe(['Protein · Recommended', 'Veggies'])
        ->and(array_column($data['sections'][0]['rows'], 'name'))->toBe(['Chicken breast', 'Egg whites 1', 'Egg whites 2', 'Egg whites 3', 'Egg whites 4'])
        ->and($data['sections'][0]['show_more_title'])->toBe('Show 25 more')
        ->and($data['sections'][0]['rows'][0]['is_favorite'])->toBeTrue()
        ->and($data['sections'][1]['rows'][0]['is_favorite'])->toBeFalse()
        ->and($data['sections'][0]['rows'][0]['cooked'])->toBe(['kcal' => '165 kcal', 'protein' => 'P 30.9', 'fat' => 'F 3.6', 'carbs' => 'C 0'])
        ->and(array_column(array_filter($data['diets'], fn (array $d) => $d['is_on']), 'key'))->toBe(['gluten_free']);
});

it('FOOD-024 offers the corrected spelling when a search finds nothing', function () {
    $user = User::factory()->create();
    pantryCurated('Chicken breast', 'protein_recommended');

    $data = pantrySearch($user, ['query' => 'chiken brest'])->assertOk()->json('data');

    assertMatchesScreenFixture('food_search', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('food_search.did-you-mean'), $data))->toBe([]);
    expect($data['suggestion'])->toBe(['text' => 'Did you mean chicken breast?', 'query' => 'chicken breast'])
        ->and($data['empty_text'])->toBe('No foods match chiken brest.');
});

it('FOOD-020 shows an empty Custom tab', function () {
    $data = pantrySearch(User::factory()->create(), ['tab' => 'custom'])->assertOk()->json('data');

    assertMatchesScreenFixture('food_search', $data);
    expect($data)->toBe(ScreenFixture::load('food_search.empty'));
});

it('FOOD-020 lists the user\'s recents newest first, favourites and custom foods by name, never another user\'s', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $oats = Food::factory()->create(['name' => 'Oats']);
    $banana = Food::factory()->create(['name' => 'Banana']);
    $theirs = Food::factory()->custom($other)->create(['name' => 'Their shake']);
    $theirOats = Food::factory()->create(['name' => 'Their oats']);
    FoodRecent::factory()->create(['user_id' => $other->id, 'food_id' => $theirOats->id, 'used_at' => '2026-11-03 08:00:00']);
    FoodFavorite::factory()->create(['user_id' => $other->id, 'food_id' => $theirOats->id]);
    FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $theirs->id]);
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

it('FOOD-020 the Recents tab lists the last 100 foods used, newest first', function () {
    $user = User::factory()->create();
    foreach (range(1, 101) as $n) {
        FoodRecent::factory()->create(['user_id' => $user->id, 'food_id' => Food::factory()->create(['name' => "Food {$n}"])->id, 'used_at' => Carbon::parse('2026-01-01')->addHours($n)]);
    }

    $names = array_column(pantrySearch($user, ['tab' => 'recents'])->json('data.sections.0.rows'), 'name');

    expect($names)->toHaveCount(100)
        ->and(array_slice($names, 0, 2))->toBe(['Food 101', 'Food 100'])
        ->and($names)->not->toContain('Food 1');
});

it('FOOD-027 ignores a saved diet filter this version does not know', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['diet_filters' => ['vegan', 'keto', 7]]);

    $diets = pantrySearch($user)->assertOk()->json('data.diets');

    expect(array_column(array_filter($diets, fn (array $d) => $d['is_on']), 'key'))->toBe(['vegan']);
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

it('FOOD-020 FOOD-027 the Recipes filter lists the user\'s own recipes, then the library\'s, that pass the diet filters and the search', function () {
    [$user, $other] = User::factory()->count(2)->create();
    Profile::factory()->for($user)->create(['diet_filters' => ['vegan']]);
    $rice = Food::factory()->create(['kcal' => 400, 'protein' => 10, 'fat' => 8, 'carbs' => 60, 'cooked_ratio' => 2.0]);
    $mine = Recipe::factory()->for($user)->create(['name' => 'Zesty bowl', 'servings' => 2, 'meal_type' => 'lunch', 'vegan' => true]);
    RecipeIngredient::factory()->create(['recipe_id' => $mine->id, 'food_id' => $rice->id, 'grams' => 200, 'is_cooked' => true]);
    Recipe::factory()->create(['name' => 'Bean burrito', 'meal_type' => 'dinner', 'vegan' => true]);
    Recipe::factory()->create(['name' => 'Beef burrito', 'meal_type' => 'dinner']);
    Recipe::factory()->create(['name' => 'Lentil soup', 'meal_type' => 'dinner', 'vegan' => true]);
    Recipe::factory()->for($other)->create(['name' => 'Their bowl', 'vegan' => true]);

    $recipes = pantrySearch($user, ['collection' => 'recipes'])->assertOk()->json('data.recipes');

    expect(array_column($recipes, 'name'))->toBe(['Zesty bowl', 'Bean burrito', 'Lentil soup'])
        ->and(array_column($recipes, 'is_library'))->toBe([false, true, true])
        ->and($recipes[0]['macros_text'])->toBe('200 kcal · P 5 · F 4 · C 30')
        ->and($recipes[0]['meal_type_title'])->toBe('Lunch')
        ->and(array_column(pantrySearch($user, ['collection' => 'recipes', 'query' => 'BURRITO'])->json('data.recipes'), 'name'))->toBe(['Bean burrito'])
        ->and(pantrySearch($user, ['collection' => 'recipes', 'query' => 'beef'])->json('data.empty_text'))->toBe('No recipes match beef.');
});

it('FOOD-027 a Recipes filter the diet filters emptied says so, counting only the user\'s and the library\'s recipes', function () {
    [$cook, $newcomer] = User::factory()->count(2)->create();
    Profile::factory()->for($cook)->create(['diet_filters' => ['vegan']]);
    Profile::factory()->for($newcomer)->create(['diet_filters' => ['vegan']]);
    Recipe::factory()->for($cook)->create(['name' => 'Beef stew']);
    $emptyText = fn (User $user) => pantrySearch($user, ['collection' => 'recipes'])->json('data.empty_text');

    expect($emptyText($cook))->toBe('No recipes match your filters.')
        ->and($emptyText($newcomer))->toBe('No recipes yet.');

    Recipe::factory()->create(['name' => 'Pork pie']);

    expect($emptyText($newcomer))->toBe('No recipes match your filters.');
});

it('FOOD-020 the Saved Meals filter lists the user\'s saved meals by name with their food counts', function () {
    $user = User::factory()->create();
    $dinner = SavedMeal::factory()->for($user)->create(['name' => 'Bulk dinner']);
    SavedMealFood::factory()->count(3)->sequence(fn ($s) => ['position' => $s->index])->create(['saved_meal_id' => $dinner->id]);
    SavedMeal::factory()->for($user)->create(['name' => 'Breakfast']);
    SavedMeal::factory()->for(User::factory()->create())->create(['name' => 'Their meal']);

    $meals = pantrySearch($user, ['collection' => 'saved_meals'])->assertOk()->json('data.saved_meals');

    expect(array_column($meals, 'detail', 'name'))->toBe(['Breakfast' => '0 foods', 'Bulk dinner' => '3 foods'])
        ->and(array_column(pantrySearch($user, ['collection' => 'saved_meals', 'query' => 'bulk'])->json('data.saved_meals'), 'name'))->toBe(['Bulk dinner'])
        ->and(pantrySearch($user, ['collection' => 'saved_meals', 'query' => '%'])->json('data'))->toMatchArray(['saved_meals' => [], 'empty_text' => 'No saved meals match %.']);
});

it('FOOD-026 a group filter narrows the Curated and Recents tabs to that group', function () {
    $user = User::factory()->create();
    $rice = pantryCurated('Rice', 'carbs_recommended');
    $cakes = pantryCurated('Rice cakes', 'carbs_sparingly');
    pantryCurated('Broccoli', 'veggies');
    foreach ([$rice, $cakes, Food::factory()->create(['name' => 'Rice pudding'])] as $food) {
        FoodRecent::factory()->create(['user_id' => $user->id, 'food_id' => $food->id]);
    }

    $curated = pantrySearch($user, ['group' => 'carbs_sparingly'])->json('data.sections');
    $recents = pantrySearch($user, ['tab' => 'recents', 'group' => 'carbs_sparingly'])->json('data.sections');

    expect(array_column($curated, 'id'))->toBe(['carbs_sparingly'])
        ->and(array_column($curated[0]['rows'], 'name'))->toBe(['Rice cakes'])
        ->and(array_column($recents[0]['rows'], 'name'))->toBe(['Rice cakes']);
});

it('FOOD-027 diet filters hide the foods that do not match all of them, in every tab, and say so when they hid them all', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['diet_filters' => ['dairy_free', 'vegan']]);
    $tofu = Food::factory()->custom($user)->create(['name' => 'Tofu', 'dairy_free' => true, 'vegan' => true]);
    $chicken = Food::factory()->custom($user)->create(['name' => 'Chicken', 'dairy_free' => true]);
    $yogurt = Food::factory()->custom($user)->create(['name' => 'Coconut yogurt', 'vegan' => true]);
    foreach ([$tofu, $chicken, $yogurt] as $food) {
        FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $food->id]);
    }
    $milk = Food::factory()->create(['name' => 'Milk']);
    FoodRecent::factory()->create(['user_id' => $user->id, 'food_id' => $milk->id]);

    $names = fn (string $tab) => array_column(pantrySearch($user, ['tab' => $tab])->json('data.sections.0.rows') ?? [], 'name');

    expect($names('custom'))->toBe(['Tofu'])
        ->and($names('favorites'))->toBe(['Tofu'])
        ->and($names('recents'))->toBe([])
        ->and(pantrySearch($user, ['tab' => 'recents'])->json('data.empty_text'))->toBe('No recent foods match your filters.')
        ->and(pantrySearch(User::factory()->create(), ['tab' => 'recents'])->json('data.empty_text'))->toBe('No recent foods yet.');
});

it('FOOD-020 the Custom and Favorites tabs list the first 100 foods by name', function () {
    $user = User::factory()->create();
    foreach (range(1, 101) as $n) {
        $food = Food::factory()->custom($user)->create(['name' => sprintf('Food %03d', $n)]);
        FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $food->id]);
    }

    foreach (['custom', 'favorites'] as $tab) {
        $names = array_column(pantrySearch($user, ['tab' => $tab])->json('data.sections.0.rows'), 'name');
        expect($names)->toHaveCount(100)
            ->and($names[0])->toBe('Food 001')
            ->and($names)->not->toContain('Food 101');
    }
});

it('FOOD-020 the Recipes filter lists the user\'s own recipes, then the library\'s, up to 100', function () {
    $user = User::factory()->create();
    Recipe::factory()->for($user)->create(['name' => 'Zz my recipe']);
    foreach (range(1, 100) as $n) {
        Recipe::factory()->create(['name' => sprintf('Library %03d', $n)]);
    }

    $names = array_column(pantrySearch($user, ['collection' => 'recipes'])->json('data.recipes'), 'name');

    expect($names)->toHaveCount(100)
        ->and($names[0])->toBe('Zz my recipe')
        ->and($names[99])->toBe('Library 099');
});

it('FOOD-021 FOOD-027 the Pantry loads only the foods it shows', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['diet_filters' => ['vegan']]);
    foreach (range(1, 40) as $n) {
        pantryCurated(sprintf('Veg %02d', $n), 'veggies', ['vegan' => true]);
        pantryCurated(sprintf('Meat %02d', $n), 'protein_recommended');
    }
    foreach (range(1, 20) as $n) {
        Food::factory()->custom($user)->create(['name' => "Cheese {$n}"]);
    }

    expect(foodsLoaded($user, []))->toBe(6)
        ->and(foodsLoaded($user, ['expanded_groups' => ['veggies']]))->toBe(30)
        ->and(foodsLoaded($user, ['tab' => 'custom']))->toBe(0);
});
