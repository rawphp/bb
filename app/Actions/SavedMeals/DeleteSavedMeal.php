<?php

namespace App\Actions\SavedMeals;

use App\Models\SavedMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** MEAL-071: delete the user's saved meal and its foods. Meals it was loaded into keep their foods. */
final class DeleteSavedMeal
{
    public function __invoke(User $user, string $savedMealId): void
    {
        $meal = SavedMeal::query()->where('user_id', $user->id)->findOrFail($savedMealId);

        DB::transaction(function () use ($meal) {
            $meal->foods()->delete();
            $meal->delete();
        });
    }
}
