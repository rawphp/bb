<?php

namespace App\Capabilities\Reads\Recipes;

use App\Actions\Foods\MealFitFoods;
use App\Capabilities\Nutrition\Data\RecipeIngredient as RecipeIngredientRow;
use App\Capabilities\Nutrition\Data\RecipeRow;
use App\Capabilities\Nutrition\Data\RecipeShowOutput;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeEdit;
use App\Domain\Recipe\RecipeMacros;
use App\Domain\Recipe\RecipeText;
use App\Models\Recipe;
use App\Models\RecipeIngredient;

/** recipes_show's rows and recipe_show's screen, from a recipe with `ingredients.food` loaded (RCP-001, RCP-020). */
final class RecipePayloads
{
    public static function row(Recipe $recipe): RecipeRow
    {
        return new RecipeRow(
            recipe_id: $recipe->id,
            name: $recipe->name,
            meal_type_title: RecipeText::mealTypeTitle($recipe->meal_type),
            macros_text: RecipeText::macros(self::perServing($recipe)),
            is_library: $recipe->user_id === null,
        );
    }

    public static function show(Recipe $recipe): RecipeShowOutput
    {
        $isLibrary = $recipe->user_id === null;

        return new RecipeShowOutput(
            recipe_id: $recipe->id,
            name: $recipe->name,
            is_read_only: $isLibrary,
            can_delete: ! $isLibrary,
            servings: $recipe->servings,
            servings_text: RecipeText::servings($recipe->servings),
            meal_type: $recipe->meal_type,
            meal_type_title: RecipeText::mealTypeTitle($recipe->meal_type),
            per_serving_text: RecipeText::macros(self::perServing($recipe), units: true),
            ingredients: $recipe->ingredients->map(function (RecipeIngredient $ingredient): RecipeIngredientRow {
                $hasCookedValue = RecipeEdit::hasCookedValue($ingredient->food->cooked_ratio);

                return new RecipeIngredientRow(
                    id: (string) $ingredient->id,
                    food_id: $ingredient->food_id,
                    name: $ingredient->food->name,
                    grams: $ingredient->grams,
                    amount_text: RecipeText::amount($ingredient->grams, $hasCookedValue, $ingredient->is_cooked),
                    is_cooked: $ingredient->is_cooked,
                    can_toggle_cooked: $hasCookedValue,
                );
            })->values()->all(),
            steps: $recipe->instructions ?? [],
        );
    }

    /** RCP-001: one serving's macros; a cooked ingredient counts its raw weight (FOOD-006). */
    private static function perServing(Recipe $recipe): MealTarget
    {
        return RecipeMacros::perServing($recipe->ingredients->map(fn (RecipeIngredient $ingredient): MealFood => new MealFood(
            id: "ingredient:{$ingredient->id}",
            food: MealFitFoods::of($ingredient->food),
            grams: $ingredient->grams,
            weighing: $ingredient->is_cooked ? Weighing::Cooked : Weighing::Raw,
        ))->values()->all(), $recipe->servings);
    }
}
