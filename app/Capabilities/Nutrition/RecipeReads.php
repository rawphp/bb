<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\Support\ReadKey;

/**
 * The read keys a change to one recipe invalidates (CAP-010): the lists as they open, the recipe's screen, and the
 * Pantry's Recipes filter (FOOD-020). A list filtered by meal type, diets or a search has a key per input, which
 * no write can name: its cached copy shows until the stale-while-revalidate read (OFF-003) brings the new one.
 */
final class RecipeReads
{
    /** @return list<string> */
    public static function keys(string $recipeId): array
    {
        return [
            ReadKey::for('recipes_show'),
            ReadKey::for('recipes_show', ['segment' => 'mine']),
            ReadKey::for('recipe_show', ['recipe_id' => $recipeId]),
            ReadKey::for('food_search', ['collection' => 'recipes']),
        ];
    }
}
