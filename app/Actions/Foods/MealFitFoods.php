<?php

namespace App\Actions\Foods;

use App\Domain\MealFit\Food as MealFitFood;
use App\Models\Food;

/** A stored food as the meal rules see it: per 100 g raw, with its cooked ratio (FOOD-004, FOOD-006). */
final class MealFitFoods
{
    public static function of(Food $food): MealFitFood
    {
        return new MealFitFood(
            id: (string) $food->id,
            name: $food->name,
            kcal: $food->kcal,
            proteinG: $food->protein,
            fatG: $food->fat,
            carbsG: $food->carbs,
            cookedRatio: $food->cooked_ratio,
        );
    }
}
