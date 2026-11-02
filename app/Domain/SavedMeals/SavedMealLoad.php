<?php

namespace App\Domain\SavedMeals;

use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;

/**
 * MEAL-070: a saved meal loaded into a meal. Its foods join the meal after what is there, one the meal already holds
 * (by food) skipped. Locked foods arrive locked at their saved amounts and the others unlocked, and the whole meal,
 * foods and recipe servings, is fitted to its target (MEAL-005, RCP-002). Every locked amount stays exactly as it was.
 */
final readonly class SavedMealLoad
{
    public function __construct(private MealFit $fit) {}

    /**
     * @param  list<MealFood>  $meal  the meal's foods and recipe servings in their order; a food's `food->id` is the food it is
     * @param  list<MealFood>  $saved  the saved meal's foods in their order, ids distinct from the meal's
     * @return list<MealFood> the meal's entries then the joined ones, fitted, each unlocked one at 1 g or more; empty when nothing joins
     */
    public function load(array $meal, array $saved, MealTarget $target): array
    {
        // Only foods already in the meal are skipped: a food the saved meal holds twice joins twice, so no saved amount is lost.
        $held = array_map(fn (MealFood $f) => $f->food->id, $meal);
        $joined = array_values(array_filter($saved, fn (MealFood $f) => ! in_array($f->food->id, $held, true)));

        if ($joined === []) {
            return [];
        }

        // The fit may leave an unlocked food or recipe serving at 0; it is planned at 1 g, a tenth of a serving.
        return array_map(
            fn (MealFood $f) => ! $f->isLocked && $f->grams < RecipeServing::MIN_GRAMS ? $f->withGrams(RecipeServing::MIN_GRAMS) : $f,
            $this->fit->fit([...$meal, ...$joined], $target)->foods,
        );
    }
}
