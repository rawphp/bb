<?php

use App\Actions\SavedMeals\LoadSavedMeal;
use App\Domain\MealFit\Food as MealFitFood;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Domain\SavedMeals\SavedMealLoad;
use App\Models\DayTarget;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Meal/SavedMealTests.swift (load). The fit is
// SavedMealLoadTest's; this proves what the action reads and writes, so it runs on the database.
uses(RefreshDatabase::class);

/** @return array{0: Food, 1: Food, 2: Food} chicken, rice and olive oil */
function loadSavedMealFoods(): array
{
    return [
        Food::factory()->create(['name' => 'Chicken breast', 'kcal' => 165, 'protein' => 31, 'fat' => 3.6, 'carbs' => 0]),
        Food::factory()->create(['name' => 'Rice', 'kcal' => 130, 'protein' => 2.7, 'fat' => 0.3, 'carbs' => 28]),
        Food::factory()->create(['name' => 'Olive oil', 'kcal' => 884, 'protein' => 0, 'fat' => 100, 'carbs' => 0]),
    ];
}

/** The user's dinner on 2026-10-13 (1040 kcal, P 68, F 28, C 128), after a breakfast: its meal id. */
function loadSavedMealDinner(User $user): string
{
    $day = DayTarget::factory()->for($user)->create(['date' => '2026-10-13', 'meals' => [
        ['name' => 'Breakfast', 'kcal' => 500, 'protein_g' => 35, 'fat_g' => 15, 'carbs_g' => 55],
        ['name' => 'Dinner', 'kcal' => 1040, 'protein_g' => 68, 'fat_g' => 28, 'carbs_g' => 128],
    ]]);

    return $day->meals[1]['id'];
}

/** "Bulk dinner": chicken locked at 300 g, rice and olive oil unlocked. */
function loadSavedMealBulkDinner(User $user, Food $chicken, Food $rice, Food $oil): SavedMeal
{
    $saved = SavedMeal::factory()->for($user)->create(['name' => 'Bulk dinner']);
    SavedMealFood::factory()->create(['saved_meal_id' => $saved->id, 'food_id' => $chicken->id, 'position' => 0, 'grams' => 300, 'is_locked' => true]);
    SavedMealFood::factory()->create(['saved_meal_id' => $saved->id, 'food_id' => $rice->id, 'position' => 1, 'grams' => 100]);
    SavedMealFood::factory()->create(['saved_meal_id' => $saved->id, 'food_id' => $oil->id, 'position' => 2, 'grams' => 5]);

    return $saved;
}

function loadSavedMealFitFood(Food $food): MealFitFood
{
    return new MealFitFood(id: $food->id, name: $food->name, kcal: $food->kcal, proteinG: $food->protein, fatG: $food->fat, carbsG: $food->carbs);
}

/** @return list<array<string, mixed>> */
function loadSavedMealPlanned(string $meal): array
{
    return PlannedFood::query()->where('meal_id', $meal)->orderBy('position')->get()
        ->map(fn (PlannedFood $f) => $f->only(['food_id', 'position', 'grams', 'is_cooked', 'is_locked', 'unit']))->all();
}

it('MEAL-070 load plans the saved foods, the locked ones at their saved grams and the rest fitted to the meal', function () {
    $user = User::factory()->create();
    [$chicken, $rice, $oil] = loadSavedMealFoods();
    $meal = loadSavedMealDinner($user);
    $saved = loadSavedMealBulkDinner($user, $chicken, $rice, $oil);

    $loaded = (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $meal, $saved->id);

    $fit = (new MealFit)->fit([
        new MealFood('c', loadSavedMealFitFood($chicken), 300, isLocked: true),
        new MealFood('r', loadSavedMealFitFood($rice), 100),
        new MealFood('o', loadSavedMealFitFood($oil), 5),
    ], new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128))->foods;
    expect($loaded)->toBe(['date' => '2026-10-13', 'name' => 'Bulk dinner', 'added' => 3])
        ->and(loadSavedMealPlanned($meal))->toBe([
            ['food_id' => $chicken->id, 'position' => 0, 'grams' => 300.0, 'is_cooked' => false, 'is_locked' => true, 'unit' => 'g'],
            ['food_id' => $rice->id, 'position' => 1, 'grams' => $fit[1]->grams, 'is_cooked' => false, 'is_locked' => false, 'unit' => 'g'],
            ['food_id' => $oil->id, 'position' => 2, 'grams' => $fit[2]->grams, 'is_cooked' => false, 'is_locked' => false, 'unit' => 'g'],
        ])
        ->and($fit[1]->grams)->not->toBe(100.0)
        ->and(PlannedFood::query()->where('meal_id', $meal)->value('user_id'))->toBe($user->id);
});

it('MEAL-070 load keeps the cooked state and unit of a saved food', function () {
    $user = User::factory()->create();
    [$chicken, , $oil] = loadSavedMealFoods();
    $meal = loadSavedMealDinner($user);
    $saved = SavedMeal::factory()->for($user)->create(['name' => 'Ounce chicken']);
    SavedMealFood::factory()->create(['saved_meal_id' => $saved->id, 'food_id' => $chicken->id, 'position' => 0, 'grams' => 200, 'is_cooked' => true, 'is_locked' => true, 'unit' => 'oz']);
    SavedMealFood::factory()->create(['saved_meal_id' => $saved->id, 'food_id' => $oil->id, 'position' => 1, 'grams' => 5]);

    (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $meal, $saved->id);

    expect(loadSavedMealPlanned($meal)[0])->toBe(['food_id' => $chicken->id, 'position' => 0, 'grams' => 200.0, 'is_cooked' => true, 'is_locked' => true, 'unit' => 'oz'])
        ->and(loadSavedMealPlanned($meal))->toHaveCount(2);
});

it('MEAL-070 load adds to the meal, skips a food already in it and refits what is there', function () {
    $user = User::factory()->create();
    [$chicken, $rice, $oil] = loadSavedMealFoods();
    $meal = loadSavedMealDinner($user);
    PlannedFood::factory()->create(['meal_id' => $meal, 'food_id' => $chicken->id, 'position' => 0, 'grams' => 150]);
    $chili = Recipe::factory()->create(['name' => 'Chili', 'servings' => 2]);
    RecipeIngredient::factory()->create(['recipe_id' => $chili->id, 'food_id' => $chicken->id, 'grams' => 200]);
    PlannedRecipe::factory()->create(['meal_id' => $meal, 'recipe_id' => $chili->id, 'position' => 1, 'servings' => 1.5, 'is_locked' => true]);
    PlannedFood::factory()->create(['meal_id' => $meal, 'food_id' => $rice->id, 'position' => 2, 'grams' => 80]);
    $saved = SavedMeal::factory()->for($user)->create(['name' => 'Rice and oil']);
    SavedMealFood::factory()->create(['saved_meal_id' => $saved->id, 'food_id' => $rice->id, 'position' => 0, 'grams' => 300, 'is_locked' => true]);
    SavedMealFood::factory()->create(['saved_meal_id' => $saved->id, 'food_id' => $oil->id, 'position' => 1, 'grams' => 5]);

    $loaded = (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $meal, $saved->id);

    // The rice was already there, so only the oil joins, after the chili; the locked chili serving stays.
    $planned = loadSavedMealPlanned($meal);
    expect($loaded['added'])->toBe(1)
        ->and(array_column($planned, 'food_id'))->toBe([$chicken->id, $rice->id, $oil->id])
        ->and(array_column($planned, 'position'))->toBe([0, 2, 3])
        ->and($planned[1]['is_locked'])->toBeFalse()
        ->and($planned[0]['grams'])->not->toBe(150.0)
        ->and(PlannedRecipe::query()->where('meal_id', $meal)->sole()->servings)->toBe(1.5);
});

it('MEAL-070 load refits an unlocked recipe serving with the foods', function () {
    $user = User::factory()->create();
    [$chicken, $rice, $oil] = loadSavedMealFoods();
    $meal = loadSavedMealDinner($user);
    $bowl = Recipe::factory()->create(['servings' => 1]);
    RecipeIngredient::factory()->create(['recipe_id' => $bowl->id, 'food_id' => $rice->id, 'grams' => 200]);
    PlannedRecipe::factory()->create(['meal_id' => $meal, 'recipe_id' => $bowl->id, 'position' => 0, 'servings' => 3]);
    $saved = loadSavedMealBulkDinner($user, $chicken, $rice, $oil);

    (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $meal, $saved->id);

    $servings = PlannedRecipe::query()->where('meal_id', $meal)->sole()->servings;
    expect($servings)->not->toBe(3.0)->and($servings)->toBeGreaterThanOrEqual(0.1);
});

it('MEAL-070 load writes nothing when every saved food is already in the meal', function () {
    $user = User::factory()->create();
    [$chicken, $rice, $oil] = loadSavedMealFoods();
    $meal = loadSavedMealDinner($user);
    foreach ([$chicken, $rice, $oil] as $position => $food) {
        PlannedFood::factory()->create(['meal_id' => $meal, 'food_id' => $food->id, 'position' => $position, 'grams' => 50]);
    }
    $saved = loadSavedMealBulkDinner($user, $chicken, $rice, $oil);

    $loaded = (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $meal, $saved->id);

    expect($loaded['added'])->toBe(0)
        ->and(array_column(loadSavedMealPlanned($meal), 'grams'))->toBe([50.0, 50.0, 50.0]);
});

it('MEAL-070 load never reads another user\'s meal or saved meal', function () {
    $user = User::factory()->create();
    [$chicken, $rice, $oil] = loadSavedMealFoods();
    $mine = loadSavedMealDinner($user);
    $theirMeal = loadSavedMealDinner(User::factory()->create());
    $theirSaved = loadSavedMealBulkDinner(User::factory()->create(), $chicken, $rice, $oil);
    $saved = loadSavedMealBulkDinner($user, $chicken, $rice, $oil);

    expect(fn () => (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $theirMeal, $saved->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $mine, $theirSaved->id))->toThrow(ModelNotFoundException::class)
        ->and(PlannedFood::query()->count())->toBe(0);
});

it('MEAL-070 load never plans into a deleted day', function () {
    $user = User::factory()->create();
    [$chicken, $rice, $oil] = loadSavedMealFoods();
    $meal = loadSavedMealDinner($user);
    DayTarget::query()->where('user_id', $user->id)->sole()->delete();
    $saved = loadSavedMealBulkDinner($user, $chicken, $rice, $oil);

    expect(fn () => (new LoadSavedMeal(new SavedMealLoad(new MealFit)))($user, $meal, $saved->id))->toThrow(ModelNotFoundException::class)
        ->and(PlannedFood::query()->count())->toBe(0);
});
