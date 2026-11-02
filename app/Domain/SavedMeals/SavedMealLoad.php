<?php

namespace App\Domain\SavedMeals;

use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;

/**
 * MEAL-070: a saved meal loaded into a meal. Its foods join the meal after what is there, one the meal already holds
 * (by food) skipped. Locked foods arrive locked at their saved amounts and the others unlocked, and the whole meal,
 * foods and recipe servings, is fitted to its target (MEAL-005, RCP-002).
 */
final readonly class SavedMealLoad
{
    public function __construct(private MealFit $fit) {}

    /**
     * @param  list<MealFood>  $meal  the meal's foods and recipe servings in their order; a food's `food->id` is the food it is
     * @param  list<MealFood>  $saved  the saved meal's foods in their order, ids distinct from the meal's
     * @return list<MealFood> the meal's entries then the joined ones, fitted, each at 1 g or more; empty when nothing joins
     */
    public function load(array $meal, array $saved, MealTarget $target): array
    {
        $held = array_map(fn (MealFood $f) => $f->food->id, $meal);
        $joined = [];
        foreach ($saved as $food) {
            if (! in_array($food->food->id, $held, true)) {
                $joined[] = $food;
                $held[] = $food->food->id;
            }
        }

        if ($joined === []) {
            return [];
        }

        // A planned food and a recipe serving both need an amount above 0; 1 g is also a tenth of a serving.
        return array_map(
            fn (MealFood $f) => $f->grams < RecipeServing::MIN_GRAMS ? $f->withGrams(RecipeServing::MIN_GRAMS) : $f,
            $this->fit->fit([...$meal, ...$joined], $target)->foods,
        );
    }
}
