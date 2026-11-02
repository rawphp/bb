<?php

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\User;

/**
 * RCP-021: delete the user's own recipe. Softly, so the meals it was planned in keep their copy and still read it;
 * the lists stop showing it. A library recipe is not the user's to delete.
 */
final class DeleteRecipe
{
    public function __invoke(User $user, string $recipeId): void
    {
        Recipe::query()->ownedBy($user)->findOrFail($recipeId)->delete();
    }
}
