<?php

namespace App\Actions\Foods;

use App\Models\FoodFavorite;
use App\Models\User;

/** FOOD-022: star a food for the user, or take the star back. Asking twice changes nothing more. */
final class SetFoodFavorite
{
    public function __invoke(User $user, string $foodId, bool $favorite): void
    {
        if ($favorite) {
            FoodFavorite::query()->firstOrCreate(['user_id' => $user->id, 'food_id' => $foodId]);

            return;
        }

        FoodFavorite::query()->where('user_id', $user->id)->where('food_id', $foodId)->delete();
    }
}
