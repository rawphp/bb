<?php

namespace App\Actions\Meals;

use App\Models\DayTargetMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** MEAL-008: one food or recipe serving of the user's meal goes. Nothing else changes and nothing is fitted again. */
final class RemoveMealFood
{
    public function __invoke(User $user, string $mealId, string $entryId): DayTargetMeal
    {
        return DB::transaction(function () use ($user, $mealId, $entryId): DayTargetMeal {
            $meal = MealEntries::load($user, $mealId);
            $meal->delete($entryId);

            return $meal->meal;
        });
    }
}
