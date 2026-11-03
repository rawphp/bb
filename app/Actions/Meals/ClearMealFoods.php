<?php

namespace App\Actions\Meals;

use App\Models\DayTargetMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** MEAL-009: the menu's Remove foods clears the meal's planned foods. Recipe servings stay: they go one by one. */
final class ClearMealFoods
{
    public function __invoke(User $user, string $mealId): DayTargetMeal
    {
        return DB::transaction(function () use ($user, $mealId): DayTargetMeal {
            $meal = MealEntries::load($user, $mealId);
            $meal->deleteFoods();

            return $meal->meal;
        });
    }
}
