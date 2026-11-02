<?php

namespace App\Actions\SavedMeals;

use App\Models\SavedMeal;
use App\Models\User;

/** MEAL-071: delete the user's saved meal; its foods go with it (cascade). Meals it was loaded into keep their foods. */
final class DeleteSavedMeal
{
    public function __invoke(User $user, string $savedMealId): void
    {
        SavedMeal::query()->where('user_id', $user->id)->findOrFail($savedMealId)->delete();
    }
}
