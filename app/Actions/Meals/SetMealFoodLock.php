<?php

namespace App\Actions\Meals;

use App\Domain\MealFit\MealEdit;
use App\Models\DayTargetMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** MEAL-005, RCP-002: lock or unlock one food or recipe serving. Only the flag changes, and nothing is fitted again. */
final class SetMealFoodLock
{
    public function __construct(private readonly MealEdit $edit) {}

    public function __invoke(User $user, string $mealId, string $entryId, bool $locked): DayTargetMeal
    {
        return DB::transaction(function () use ($user, $mealId, $entryId, $locked): DayTargetMeal {
            $meal = MealEntries::load($user, $mealId);
            $entry = $meal->entry($entryId);

            $meal->save($this->edit->lock($meal->entries(), $entry->id, $locked));

            return $meal->meal;
        });
    }
}
