<?php

namespace App\Actions\Meals;

use App\Models\DayTargetMeal;
use App\Models\MealPlanningState;
use App\Models\User;
use App\Support\Today\TodayMealItem;

/**
 * DAY-004: "Done planning foods" or "Still planning foods". A meal done planning shows no "Adjust foods" badge, so it
 * can be checked in with one tap (MEAL-045). The state is set, not flipped, so a replayed write lands the same.
 */
final class SetMealPlanningState
{
    public const DONE_PLANNING = 'done_planning';

    public function __invoke(User $user, string $mealId, bool $done): DayTargetMeal
    {
        $meal = DayTargetMeal::query()->liveOf($user)->with('dayTarget')->findOrFail($mealId);
        MealPlanningState::query()->updateOrCreate(
            ['meal_id' => $meal->getKey()],
            ['user_id' => $user->id, 'state' => $done ? self::DONE_PLANNING : TodayMealItem::STILL_PLANNING],
        );

        return $meal;
    }
}
