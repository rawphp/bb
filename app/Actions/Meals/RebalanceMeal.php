<?php

namespace App\Actions\Meals;

use App\Domain\MealFit\MealEdit;
use App\Models\DayTargetMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** MEAL-006: every unlocked food and recipe serving of the user's meal is fitted to its targets again. */
final class RebalanceMeal
{
    public function __construct(private readonly MealEdit $edit) {}

    public function __invoke(User $user, string $mealId): DayTargetMeal
    {
        return DB::transaction(function () use ($user, $mealId): DayTargetMeal {
            $meal = MealEntries::load($user, $mealId);

            $meal->save($this->edit->planned($this->edit->rebalance($meal->entries(), $meal->target())));

            return $meal->meal;
        });
    }
}
