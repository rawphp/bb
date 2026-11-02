<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\Support\ReadKey;

/** The reads a change to the user's saved meals changes (CAP-011, CAP-017): the list and the Pantry's Saved Meals (FOOD-020). */
final class SavedMealReads
{
    public const AFFECTS = ['saved_meals_show', 'food_search'];

    /** @return list<string> */
    public static function keys(): array
    {
        return [ReadKey::for('saved_meals_show'), ReadKey::for('food_search', ['collection' => 'saved_meals'])];
    }
}
