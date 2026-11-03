<?php

namespace App\Actions\Meals;

use App\Models\DayTargetMeal;
use App\Models\User;
use DomainException;

/** MEAL-044: remove a meal's check-in. A meal with the "Adjust foods" badge may lose its check-in too (MEAL-045). */
final class RemoveMealCheckIn
{
    public function __invoke(User $user, string $mealId): DayTargetMeal
    {
        $meal = DayTargetMeal::query()->liveOf($user)->with('dayTarget')->findOrFail($mealId);
        $checkIn = $meal->checkIn ?? throw new DomainException('This meal is not checked in.');
        $checkIn->delete();

        return $meal;
    }
}
