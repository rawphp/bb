<?php

namespace App\Domain\Recipe;

use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;

/**
 * RCP-002: servings of a recipe in a meal, as a food the meal fit can scale: 1 g is a tenth of a serving. The fit
 * rounds grams to 1 g, so servings land on 0.1 steps, and a locked serving is held like a locked food.
 */
final class RecipeServing
{
    public const GRAMS_PER_SERVING = 10.0;

    /** A serving in a meal is never below a tenth of a serving. */
    public const MIN_SERVINGS = 0.1;

    /** MIN_SERVINGS as the fit sees it: 1 g. */
    public const MIN_GRAMS = self::MIN_SERVINGS * self::GRAMS_PER_SERVING;

    /**
     * A food whose per-100 g values are ten times `perServing`, so `servings` of it weigh `servings × 10` g and add
     * up to `servings × perServing`. `id` names the serving within its meal and is also the food's id.
     */
    public static function food(
        string $id,
        string $name,
        MealTarget $perServing,
        float $servings,
        bool $isLocked = false,
    ): MealFood {
        return new MealFood(
            id: $id,
            food: new Food(
                id: $id,
                name: $name,
                kcal: $perServing->kcal * self::GRAMS_PER_SERVING,
                proteinG: $perServing->proteinG * self::GRAMS_PER_SERVING,
                fatG: $perServing->fatG * self::GRAMS_PER_SERVING,
                carbsG: $perServing->carbsG * self::GRAMS_PER_SERVING,
            ),
            grams: $servings * self::GRAMS_PER_SERVING,
            isLocked: $isLocked,
        );
    }

    /** The servings the food stands for, to a millionth so a tenth multiplied out and back reads as typed. */
    public static function servings(MealFood $food): float
    {
        return round($food->grams / self::GRAMS_PER_SERVING * 1_000_000) / 1_000_000;
    }
}
