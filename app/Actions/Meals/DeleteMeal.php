<?php

namespace App\Actions\Meals;

use App\Models\DayTargetMeal;
use App\Models\User;

/**
 * MEAL-009: delete a meal from its day. Its day target is saved without it, and the day's meal index then deletes the
 * meal's row, which deletes its planned foods, planned recipes, check-in and planning state through their models first,
 * so undo sees every row (CAP-013). No other meal is re-targeted.
 */
final class DeleteMeal
{
    /** @return list<DayTargetMeal> the day's meals as they were, the deleted one included, in their order */
    public function __invoke(User $user, string $mealId): array
    {
        $meal = DayTargetMeal::query()->liveOf($user)->with('dayTarget')->findOrFail($mealId);
        $day = $meal->dayTarget;
        $meals = $day->mealIndex()->with('dayTarget')->orderBy('position')->get()->all();

        $id = strtolower((string) $meal->getKey());
        $day->meals = array_values(array_filter($day->meals, fn (array $entry) => strtolower((string) ($entry['id'] ?? '')) !== $id));
        $day->save();

        return $meals;
    }
}
