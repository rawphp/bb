<?php

namespace Tests\Support;

use App\Models\DayTarget;
use App\Models\Food;
use App\Models\FoodServing;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;

/** Rows for the meal builder's tests: a dinner (1040 kcal, P 68, F 28, C 128) after a breakfast, and the foods to plan in it. */
final class MealBuilderSeed
{
    /**
     * @param  array<string, int>|null  $target  the dinner's kcal, protein_g, fat_g and carbs_g
     * @return string the id of the user's dinner on 2026-10-13
     */
    public static function dinner(User $user, ?array $target = null): string
    {
        $day = DayTarget::factory()->for($user)->create(['date' => '2026-10-13', 'meals' => [
            ['name' => 'Breakfast', 'kcal' => 500, 'protein_g' => 35, 'fat_g' => 15, 'carbs_g' => 55],
            ['name' => 'Dinner'] + ($target ?? ['kcal' => 1040, 'protein_g' => 68, 'fat_g' => 28, 'carbs_g' => 128]),
        ]]);

        return $day->meals[1]['id'];
    }

    /** @return string the id of the user's breakfast on 2026-10-13 (the first meal of {@see dinner()}'s day) */
    public static function breakfast(User $user): string
    {
        return DayTarget::query()->where('user_id', $user->id)->firstOrFail()->meals[0]['id'];
    }

    /** @param  array<string, mixed>  $attributes */
    private static function food(array $attributes): Food
    {
        return Food::query()->findOrFail(Food::factory()->createOne($attributes)->getKey());
    }

    /** @param  array<string, mixed>  $attributes */
    private static function recipe(array $attributes): Recipe
    {
        return Recipe::query()->findOrFail(Recipe::factory()->createOne($attributes)->getKey());
    }

    public static function chicken(): Food
    {
        $chicken = self::food(['name' => 'Chicken breast', 'kcal' => 165, 'protein' => 31, 'fat' => 3.6, 'carbs' => 0, 'cooked_ratio' => 0.75]);
        FoodServing::factory()->create(['food_id' => $chicken->id, 'label' => 'fillet', 'grams' => 150]);

        return $chicken;
    }

    public static function rice(): Food
    {
        $rice = self::food(['name' => 'Rice', 'kcal' => 130, 'protein' => 2.7, 'fat' => 0.3, 'carbs' => 28]);
        FoodServing::factory()->create(['food_id' => $rice->id, 'label' => 'cup', 'grams' => 185]);

        return $rice;
    }

    public static function oil(): Food
    {
        return self::food(['name' => 'Olive oil', 'kcal' => 884, 'protein' => 0, 'fat' => 100, 'carbs' => 0, 'cooked_ratio' => null]);
    }

    /** A recipe of 4 servings with 400 g of beef: 250 kcal, P 26, F 15 a serving. */
    public static function stew(?User $owner = null): Recipe
    {
        $stew = self::recipe(['name' => 'Stew', 'servings' => 4, 'user_id' => $owner?->id]);
        $beef = self::food(['name' => 'Beef mince', 'kcal' => 250, 'protein' => 26, 'fat' => 15, 'carbs' => 0, 'cooked_ratio' => null]);
        RecipeIngredient::factory()->create(['recipe_id' => $stew->id, 'food_id' => $beef->id, 'grams' => 400]);

        return $stew;
    }

    public static function planFood(string $meal, Food $food, float $grams, int $position, bool $locked = false, string $unit = 'g', bool $cooked = false): PlannedFood
    {
        return PlannedFood::factory()->createOne([
            'meal_id' => $meal, 'food_id' => $food->id, 'position' => $position, 'grams' => $grams, 'is_locked' => $locked, 'unit' => $unit, 'is_cooked' => $cooked,
        ]);
    }

    public static function planRecipe(string $meal, Recipe $recipe, float $servings, int $position, bool $locked = false): PlannedRecipe
    {
        $recipe->load('ingredients');

        return PlannedRecipe::factory()->createOne([
            'meal_id' => $meal, 'recipe_id' => $recipe->id, 'position' => $position, 'servings' => $servings, 'is_locked' => $locked, 'recipe' => $recipe->plannedCopy(),
        ]);
    }

    /** @return array<string, float> the meal's planned foods by food name */
    public static function foodGrams(string $meal): array
    {
        return PlannedFood::query()->where('meal_id', $meal)->with('food')->orderBy('position')->get()
            ->mapWithKeys(fn (PlannedFood $f) => [$f->food->name => $f->grams])->all();
    }
}
