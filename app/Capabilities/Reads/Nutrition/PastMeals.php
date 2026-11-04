<?php

namespace App\Capabilities\Reads\Nutrition;

use App\Domain\Copy\MealRepeats;
use App\Domain\Copy\PastMeal;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;

/**
 * MEAL-061: the user's own meals that held foods on the days before a day, as far back as {@see MealRepeats} looks,
 * named as Today names them. One query for the days, one for their meals with foods and their foods.
 */
final class PastMeals
{
    /** @return list<PastMeal> */
    public static function before(User $user, DateTimeImmutable $day): array
    {
        $range = [$day->modify('-'.MealRepeats::LOOKBACK_DAYS.' days')->format('Y-m-d'), $day->modify('-1 day')->format('Y-m-d')];
        $targets = DayTarget::query()->where('user_id', $user->id)->whereBetween('date', $range)->get();
        $rows = DayTargetMeal::query()->liveOf($user)->whereIn('day_target_id', $targets->modelKeys())
            ->where(fn ($query) => $query->whereHas('plannedFoods')->orWhereHas('plannedRecipes'))
            ->with(['plannedFoods.food', 'plannedRecipes.sourceRecipe'])
            ->get()
            ->keyBy(fn (DayTargetMeal $meal) => strtolower((string) $meal->id));

        $meals = [];
        foreach ($targets as $target) {
            foreach (TodaySnapshot::json($target->meals) as $position => $meal) {
                $row = $rows->get(strtolower((string) ($meal['id'] ?? '')));
                if ($row === null) {
                    continue;
                }
                $entries = [
                    ...$row->plannedFoods->map(fn (PlannedFood $f) => [$f->position, 'food:'.$f->food_id, $f->food->name ?? 'Food']),
                    ...$row->plannedRecipes->map(fn (PlannedRecipe $r) => [$r->position, 'recipe:'.$r->recipe_id, TodaySnapshot::recipeName($r)]),
                ];
                usort($entries, fn (array $a, array $b) => $a[0] <=> $b[0]);
                $meals[] = new PastMeal(
                    mealId: (string) $row->id,
                    day: TrainSnapshot::day($target->date),
                    // As TodaySnapshot::meals() names it.
                    name: ($meal['name'] ?? null) ?: 'Meal '.($position + 1),
                    entryKeys: array_column($entries, 1),
                    entryNames: array_column($entries, 2),
                );
            }
        }

        return $meals;
    }
}
