<?php

namespace App\Actions\Foods;

use App\Domain\MealFit\Food as MealFitFood;
use App\Domain\MealFit\Serving;
use App\Models\Food;
use App\Models\FoodServing;

/**
 * A stored food as the meal rules see it: per 100 g raw, with its cooked ratio (FOOD-004, FOOD-006), and its household
 * units in stored order when its servings are loaded (FOOD-005).
 */
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
            servings: $food->relationLoaded('servings')
                ? $food->servings->sortBy('id')->map(fn (FoodServing $s) => new Serving((string) $s->label, (float) $s->grams))->values()->all()
                : [],
        );
    }
}
