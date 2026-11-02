<?php

namespace App\Domain\Recipe;

use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;

/** RCP-001, RCP-002: the macros one serving of a recipe gives. */
final class RecipeMacros
{
    /**
     * The ingredients' calories and macros added up and divided by the recipe's servings. A cooked ingredient
     * counts its raw grams (FOOD-006), like a food in a meal. A recipe with no servings has no macros.
     *
     * @param  list<MealFood>  $ingredients
     */
    public static function perServing(array $ingredients, float $servings): MealTarget
    {
        if ($servings <= 0) {
            return new MealTarget(kcal: 0, proteinG: 0, fatG: 0, carbsG: 0);
        }

        $total = (new MealFit)->totals($ingredients);

        return new MealTarget(
            kcal: $total->kcal / $servings,
            proteinG: $total->proteinG / $servings,
            fatG: $total->fatG / $servings,
            carbsG: $total->carbsG / $servings,
        );
    }
}
