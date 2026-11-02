<?php

namespace App\Actions\Foods;

use App\Models\Food;
use App\Models\User;

/**
 * FOOD-061: delete the user's custom food. Softly, so the meals, recipes and saved meals that use it
 * keep reading it; search, barcode lookup and the manager stop showing it.
 */
final class DeleteCustomFood
{
    public function __invoke(User $user, string $foodId): void
    {
        Food::query()->where('user_id', $user->id)->findOrFail($foodId)->delete();
    }
}
