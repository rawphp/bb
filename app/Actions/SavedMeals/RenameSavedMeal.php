<?php

namespace App\Actions\SavedMeals;

use App\Domain\SavedMeals\SavedMeals;
use App\Models\SavedMeal;
use App\Models\User;
use DomainException;

/** MEAL-071: rename the user's saved meal. The name is trimmed; a blank one is refused and the same one changes nothing. */
final class RenameSavedMeal
{
    public function __invoke(User $user, string $savedMealId, string $name): SavedMeal
    {
        $meal = SavedMeal::query()->where('user_id', $user->id)->findOrFail($savedMealId);
        $name = SavedMeals::name($name) ?? throw new DomainException('Give the saved meal a name.');

        if ($meal->name !== $name) {
            $meal->update(['name' => $name]);
        }

        return $meal;
    }
}
