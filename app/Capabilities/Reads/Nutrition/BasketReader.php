<?php

namespace App\Capabilities\Reads\Nutrition;

use App\Actions\Foods\MealFitFoods;
use App\Actions\Meals\AddMealFoods;
use App\Actions\Meals\MealEntries;
use App\Capabilities\Nutrition\Data\MealFoodAddEntry;
use App\Capabilities\Nutrition\Data\MealFoodAddShowOutput;
use App\Capabilities\Support\FieldRefused;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Recipe\RecipeServing;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Today\TodaySnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * FOOD-030: the basket's entries as {@see AddMealFoods} would join them, read and not saved: a food
 * or recipe the meal (or an earlier entry) already holds is skipped, a food weighed cooked counts cooked when it has a
 * cooked value, an entry with grams joins locked at them, and a recipe given in grams is refused. Two queries for the picks, one for the meal.
 */
final class BasketReader
{
    /**
     * @param  list<MealFoodAddEntry>  $entries
     *
     * @throws ModelNotFoundException when the meal, a food or a recipe is not the user's to use
     */
    public static function show(User $user, string $mealId, array $entries): MealFoodAddShowOutput
    {
        $meal = MealEntries::read($user, $mealId);
        $foods = Food::query()->visibleTo($user)->with('servings')
            ->whereIn('id', array_values(array_filter(array_map(fn (MealFoodAddEntry $e) => $e->food_id, $entries))))
            ->get()->keyBy(fn (Food $food) => strtolower((string) $food->id));
        $recipes = Recipe::query()->visibleTo($user)->with('ingredients.food')
            ->whereIn('id', array_values(array_filter(array_map(fn (MealFoodAddEntry $e) => $e->recipe_id, $entries))))
            ->get()->keyBy(fn (Recipe $recipe) => strtolower((string) $recipe->id));

        $seen = [];
        $picks = [];
        foreach ($entries as $index => $entry) {
            if ($entry->recipe_id !== null && $entry->grams !== null) {
                throw new FieldRefused("entries.{$index}.grams", AddMealFoods::RECIPE_IN_SERVINGS);
            }
            $id = strtolower($entry->id);
            $key = $entry->recipe_id !== null ? 'recipe-'.strtolower($entry->recipe_id) : 'food-'.strtolower((string) $entry->food_id);
            $held = $meal->holds($id) || isset($seen[$key])
                || ($entry->recipe_id !== null ? $meal->holdsRecipe($entry->recipe_id) : $meal->holdsFood((string) $entry->food_id));
            $seen[$key] = true;

            if ($held) {
                $picks[] = ['id' => $entry->id, 'fit' => null];
            } elseif ($entry->recipe_id !== null) {
                $recipe = $recipes[strtolower($entry->recipe_id)] ?? throw (new ModelNotFoundException)->setModel(Recipe::class, [$entry->recipe_id]);
                $picks[] = ['id' => $entry->id, 'isServing' => true, 'fit' => RecipeServing::food($id, $recipe->name, TodaySnapshot::recipePerServing($recipe), 0)];
            } else {
                $food = $foods[strtolower((string) $entry->food_id)] ?? throw (new ModelNotFoundException)->setModel(Food::class, [$entry->food_id]);
                $cooked = $entry->weighing === Weighing::Cooked->value && $food->cooked_ratio !== null && $food->cooked_ratio > 0;
                $picks[] = ['id' => $entry->id, 'fit' => new MealFood(
                    id: $id,
                    food: MealFitFoods::of($food),
                    grams: $entry->grams ?? 0.0,
                    weighing: $cooked ? Weighing::Cooked : Weighing::Raw,
                    isLocked: $entry->grams !== null,
                )];
            }
        }

        return BasketPayload::build($meal->entries(), $picks, $meal->target());
    }
}
