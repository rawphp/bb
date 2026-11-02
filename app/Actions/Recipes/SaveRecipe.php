<?php

namespace App\Actions\Recipes;

use App\Domain\Model\DietFilter;
use App\Domain\Recipe\RecipeEdit;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RCP-001: create or edit the user's recipe, replacing its ingredients and steps. Its diet flags are the ones every
 * ingredient passes (FOOD-027). RCP-021: an edit reaches the copy in every meal the recipe is planned in that is not
 * checked in; a checked-in meal keeps the copy it had.
 */
final class SaveRecipe
{
    /**
     * @param  Recipe|null  $recipe  the user's recipe to edit; null creates one under $id
     * @param  list<array{food_id: string, grams: float|int, is_cooked: bool}>  $ingredients  foods the user can see (the authorizer checked)
     * @param  list<string>  $steps
     */
    public function __invoke(User $user, ?Recipe $recipe, string $id, string $name, float $servings, string $mealType, array $ingredients, array $steps): Recipe
    {
        return DB::transaction(function () use ($user, $recipe, $id, $name, $servings, $mealType, $ingredients, $steps): Recipe {
            // A food deleted since it was picked still counts: the recipes that use it keep it (FOOD-061).
            $foods = Food::withTrashed()->visibleTo($user)->findMany(array_column($ingredients, 'food_id'))->keyBy('id');

            if ($recipe === null) {
                $recipe = new Recipe;
                $recipe->id = $id;
                $recipe->user_id = $user->id;
            }

            $recipe->fill([
                'name' => trim($name),
                'servings' => $servings,
                'meal_type' => $mealType,
                'instructions' => RecipeEdit::steps($steps),
            ])->forceFill(DietFilter::sharedBy($foods->map(fn (Food $food): array => $food->only(Food::DIETS))->values()->all()))
                ->save();

            $recipe->ingredients()->delete();
            $recipe->ingredients()->createMany(array_map(fn (array $ingredient, int $position): array => [
                'food_id' => $ingredient['food_id'],
                'position' => $position,
                'grams' => (float) $ingredient['grams'],
                'is_cooked' => RecipeEdit::isCooked($ingredient['is_cooked'], $foods->get($ingredient['food_id'])?->cooked_ratio),
            ], $ingredients, array_keys($ingredients)));

            $recipe->load('ingredients');
            PlannedRecipe::query()
                ->where('recipe_id', $recipe->id)
                ->whereNotIn('meal_id', MealCheckIn::query()->where('user_id', $user->id)->select('meal_id'))
                ->update(['recipe' => json_encode($recipe->plannedCopy(), JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $recipe;
        });
    }
}
