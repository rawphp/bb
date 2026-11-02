<?php

use App\Models\Food;
use App\Models\Recipe;
use Database\Seeders\CuratedFoodSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RecipeSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(CuratedFoodSeeder::class);
    $this->seed(RecipeSeeder::class);
});

it('RCP-020 seeds at least 150 library recipes built only from curated foods', function () {
    $library = Recipe::whereNull('user_id');

    expect($library->count())->toBeGreaterThanOrEqual(150)
        ->and(Recipe::whereNotNull('user_id')->count())->toBe(0)
        ->and(Recipe::whereNull('user_id')->distinct()->count('name'))->toBe(Recipe::whereNull('user_id')->count())
        ->and(Recipe::whereNull('user_id')->doesntHave('ingredients')->count())->toBe(0)
        ->and(Recipe::whereNull('user_id')->where('servings', '<=', 0)->count())->toBe(0)
        ->and(DB::table('recipe_ingredients')->where('grams', '<=', 0)->count())->toBe(0);

    $foodIds = DB::table('recipe_ingredients')->distinct()->pluck('food_id');
    expect(Food::whereIn('id', $foodIds)->where('curated', false)->count())->toBe(0);
});

it('RCP-020 keeps each recipe\'s ingredients in the data file\'s order, weighed raw', function () {
    [$name, $mealType, $servings, $ingredients, $steps] = (require database_path('seeders/data/recipes.php'))[0];

    $recipe = Recipe::with('ingredients.food')->where('name', $name)->firstOrFail();

    expect($recipe->meal_type)->toBe($mealType)
        ->and($recipe->servings)->toBe((float) $servings)
        ->and($recipe->instructions)->toBe($steps)
        ->and($recipe->ingredients->map(fn ($i) => [$i->food->name, $i->grams])->all())
        ->toBe(array_map(fn ($i) => [$i[0], (float) $i[1]], $ingredients))
        ->and($recipe->ingredients->pluck('position')->all())->toBe(array_keys($ingredients))
        ->and($recipe->ingredients->every(fn ($i) => $i->is_cooked === false))->toBeTrue();
});

it('RCP-020 has library recipes for every meal type', function (string $mealType) {
    $recipes = Recipe::ownedBy(null)->filtered($mealType, [])->get();

    expect($recipes->count())->toBeGreaterThanOrEqual(10)->toBeLessThan(Recipe::count())
        ->and($recipes->pluck('meal_type')->unique()->values()->all())->toBe([$mealType]);
})->with(Recipe::MEAL_TYPES);

it('FOOD-027 RCP-020 has library recipes for every diet filter, true only when every ingredient passes', function (string $diet) {
    $recipes = Recipe::ownedBy(null)->filtered(null, [$diet])->with('ingredients.food')->get();

    expect($recipes->count())->toBeGreaterThanOrEqual(5)->toBeLessThan(Recipe::count());

    foreach (Recipe::with('ingredients.food')->get() as $recipe) {
        expect($recipe->{$diet})->toBe($recipe->ingredients->every(fn ($i) => $i->food->{$diet}), "{$recipe->name}: {$diet}");
    }
})->with(Food::DIETS);

it('RCP-020 can seed the library again without duplicating it', function () {
    $count = Recipe::count();
    $ingredients = DB::table('recipe_ingredients')->count();

    $this->seed(RecipeSeeder::class);

    expect(Recipe::count())->toBe($count)
        ->and(DB::table('recipe_ingredients')->count())->toBe($ingredients);
});

it('RCP-020 builds the library from curated foods, not other foods with the same name', function () {
    $name = (require database_path('seeders/data/recipes.php'))[0][3][0][0];
    $lookalike = Food::factory()->create(['name' => $name]);

    $this->seed(RecipeSeeder::class);

    expect(DB::table('recipe_ingredients')->where('food_id', $lookalike->id)->count())->toBe(0);
});

it('RCP-020 is part of the default seed', function () {
    DB::table('recipe_ingredients')->delete();
    Recipe::query()->forceDelete();

    $this->seed(DatabaseSeeder::class);

    expect(Recipe::whereNull('user_id')->count())->toBeGreaterThanOrEqual(150);
});
