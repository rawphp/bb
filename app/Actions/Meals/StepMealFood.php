<?php

namespace App\Actions\Meals;

use App\Domain\MealFit\MealEdit;
use App\Models\DayTargetMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** MEAL-004, RCP-002: + or − on one food (5 g) or recipe serving (half a serving); the other unlocked entries rebalance. */
final class StepMealFood
{
    public function __construct(private readonly MealEdit $edit) {}

    /** @param  string  $direction  'up' or 'down' */
    public function __invoke(User $user, string $mealId, string $entryId, string $direction): DayTargetMeal
    {
        return DB::transaction(function () use ($user, $mealId, $entryId, $direction): DayTargetMeal {
            $meal = MealEntries::load($user, $mealId);
            $entry = $meal->entry($entryId);

            $meal->save($this->edit->planned($this->edit->step(
                $meal->entries(), $entry->id, $direction === 'up', $meal->isServing($entryId), $meal->target(),
            )));

            return $meal->meal;
        });
    }
}
