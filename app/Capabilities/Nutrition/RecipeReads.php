<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\Support\ReadKey;

/** The read keys a change to one recipe invalidates (CAP-010): the lists as they open, and the recipe's screen. */
final class RecipeReads
{
    /** @return list<string> */
    public static function keys(string $recipeId): array
    {
        return [
            ReadKey::for('recipes_show'),
            ReadKey::for('recipes_show', ['segment' => 'mine']),
            ReadKey::for('recipe_show', ['recipe_id' => $recipeId]),
        ];
    }
}
