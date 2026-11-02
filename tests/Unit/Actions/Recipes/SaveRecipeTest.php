<?php

use App\Actions\Recipes\SaveRecipe;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

// The action's job is the recipe rows it keeps and the planned copies it refreshes, so it runs on the database.
uses(RefreshDatabase::class);

/** @return list<array{food_id: string, grams: float, is_cooked: bool}> */
function recipeLines(Food ...$foods): array
{
    return array_map(fn (Food $food) => ['food_id' => $food->id, 'grams' => 100.0, 'is_cooked' => false], $foods);
}

function recipeMeal(User $user): DayTargetMeal
{
    return DayTargetMeal::factory()->create(['day_target_id' => DayTarget::factory()->for($user)->state(['meals' => []])]);
}

it('RCP-001 creates the user\'s recipe under the client\'s id, with its ingredients in order and its steps', function () {
    $user = User::factory()->create();
    $chicken = Food::factory()->create(['name' => 'Chicken breast', 'cooked_ratio' => 0.75]);
    $rice = Food::factory()->create(['name' => 'Rice']);
    $id = (string) Str::ulid();

    (new SaveRecipe)($user, null, $id, '  Chicken plate ', 2, 'lunch', [
        ['food_id' => $chicken->id, 'grams' => 250, 'is_cooked' => true],
        ['food_id' => $rice->id, 'grams' => 120.5, 'is_cooked' => false],
    ], ['Cook the rice.', '  ', ' Grill the chicken. ']);

    $recipe = Recipe::query()->findOrFail($id);
    expect($recipe)
        ->user_id->toBe($user->id)
        ->slug->toBeNull()
        ->name->toBe('Chicken plate')
        ->servings->toBe(2.0)
        ->meal_type->toBe('lunch')
        ->instructions->toBe(['Cook the rice.', 'Grill the chicken.'])
        ->and($recipe->ingredients->map->only(['food_id', 'position', 'grams', 'is_cooked'])->all())->toBe([
            ['food_id' => $chicken->id, 'position' => 0, 'grams' => 250.0, 'is_cooked' => true],
            ['food_id' => $rice->id, 'position' => 1, 'grams' => 120.5, 'is_cooked' => false],
        ]);
});

it('RCP-001 FOOD-006 an ingredient is kept cooked only when its food has a cooked value', function () {
    $user = User::factory()->create();
    $beans = Food::factory()->create(['cooked_ratio' => null]);

    $recipe = (new SaveRecipe)($user, null, (string) Str::ulid(), 'Beans', 1, 'lunch', [
        ['food_id' => $beans->id, 'grams' => 240, 'is_cooked' => true],
    ], []);

    expect($recipe->ingredients()->sole()->is_cooked)->toBeFalse();
});

it('FOOD-027 RCP-020 a recipe passes a diet filter only when every ingredient does', function () {
    $user = User::factory()->create();
    $oats = Food::factory()->create(['vegan' => true, 'vegetarian' => true, 'dairy_free' => true]);
    $milk = Food::factory()->create(['vegan' => false, 'vegetarian' => true, 'dairy_free' => false]);

    $recipe = (new SaveRecipe)($user, null, (string) Str::ulid(), 'Oats', 1, 'breakfast', recipeLines($oats, $milk), []);

    expect($recipe->fresh())
        ->vegetarian->toBeTrue()
        ->vegan->toBeFalse()
        ->dairy_free->toBeFalse()
        ->gluten_free->toBeFalse();
});

it('RCP-001 an edit replaces the whole recipe in place', function () {
    $user = User::factory()->create();
    [$oats, $whey, $berries] = Food::factory()->count(3)->create();
    $id = (string) Str::ulid();
    $save = new SaveRecipe;
    $save($user, null, $id, 'Oats', 1, 'breakfast', recipeLines($oats, $whey), ['Mix.']);

    $save($user, Recipe::query()->findOrFail($id), $id, 'Berry oats', 3, 'snack', recipeLines($berries, $oats), []);

    $recipe = Recipe::query()->sole();
    expect($recipe)
        ->id->toBe($id)
        ->name->toBe('Berry oats')
        ->servings->toBe(3.0)
        ->meal_type->toBe('snack')
        ->instructions->toBe([])
        ->and($recipe->ingredients->pluck('food_id')->all())->toBe([$berries->id, $oats->id]);
});

it('RCP-021 an edit reaches every meal the recipe is planned in, except a checked-in one, which keeps its copy', function () {
    $user = User::factory()->create();
    $oats = Food::factory()->create();
    $id = (string) Str::ulid();
    $save = new SaveRecipe;
    $recipe = $save($user, null, $id, 'Oats', 1, 'breakfast', recipeLines($oats), ['Mix.']);
    $open = PlannedRecipe::factory()->create(['meal_id' => recipeMeal($user), 'recipe_id' => $id, 'recipe' => $recipe->plannedCopy()]);
    $eatenMeal = recipeMeal($user);
    MealCheckIn::factory()->create(['meal_id' => $eatenMeal->id]);
    $eaten = PlannedRecipe::factory()->create(['meal_id' => $eatenMeal, 'recipe_id' => $id, 'recipe' => $recipe->plannedCopy()]);
    $before = $recipe->plannedCopy();

    $edited = $save($user, $recipe->fresh(), $id, 'Oats XL', 2, 'breakfast', [['food_id' => $oats->id, 'grams' => 160, 'is_cooked' => false]], ['Mix well.']);

    expect($open->fresh()->recipe)->toEqual($edited->plannedCopy())
        ->and($open->fresh()->recipe)->toMatchArray(['name' => 'Oats XL', 'servings' => 2, 'instructions' => ['Mix well.']])
        ->and($open->fresh()->recipe['ingredients'])->toEqual([['food_id' => $oats->id, 'grams' => 160, 'is_cooked' => false]])
        ->and($eaten->fresh()->recipe)->toEqual($before);
});

it('RCP-021 an edit leaves another recipe\'s planned copies alone', function () {
    $user = User::factory()->create();
    $oats = Food::factory()->create();
    $other = Recipe::factory()->for($user)->create();
    $copy = ['id' => $other->id, 'name' => $other->name];
    $planned = PlannedRecipe::factory()->create(['meal_id' => recipeMeal($user), 'recipe_id' => $other->id, 'recipe' => $copy]);
    $id = (string) Str::ulid();
    $save = new SaveRecipe;
    $save($user, null, $id, 'Oats', 1, 'breakfast', recipeLines($oats), []);

    $save($user, Recipe::query()->findOrFail($id), $id, 'Oats XL', 1, 'breakfast', recipeLines($oats), []);

    expect($planned->fresh()->recipe)->toBe($copy);
});
