<?php

namespace App\Actions\Today;

use App\Models\MealPlanningState;
use App\Models\User;
use App\Support\Today\DayMeal;
use App\Support\Today\DayPlans;
use App\Support\Today\Redistribution;
use App\Support\UserDay;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * DAY-002, DAY-003: spreads the day's remaining calories and macros over the chosen meals and saves the day with the
 * new meal targets, its totals unchanged. Each chosen meal that has foods is marked "Still planning foods" (DAY-004),
 * so its card shows "Adjust foods" until they are re-fitted. A meal that cannot take a share is refused, not skipped.
 */
final class RedistributeMeals
{
    /**
     * @param  list<string>  $mealIds
     * @return list<DayMeal> the meals that took a share
     */
    public function __invoke(User $user, string $date, array $mealIds): array
    {
        $plans = DayPlans::load($user, [$date]);
        $day = $plans->plan($date) ?? throw new DomainException('This day has no meals planned yet.');
        if ($mealIds === []) {
            throw new DomainException('Choose at least one meal to take the calories.');
        }
        // DayTarget stores meal ids lowercased; a client may send a ULID in capitals.
        $mealIds = array_map(strtolower(...), $mealIds);
        $receivable = Redistribution::receivable($day, UserDay::now($user));
        $receivableIds = array_map(fn (DayMeal $meal) => $meal->id, $receivable);
        foreach ($mealIds as $id) {
            if (! in_array($id, $receivableIds, true)) {
                $name = $day->meal($id)->name ?? 'That meal';
                throw new DomainException("{$name} can't take a share: it is checked in, past, or its foods are locked.");
            }
        }
        $chosen = array_values(array_filter($receivable, fn (DayMeal $meal) => in_array($meal->id, $mealIds, true)));

        // The day and the planning states are saved together or not at all.
        DB::transaction(function () use ($plans, $date, $day, $chosen, $user) {
            $row = $plans->row($date);
            $row->meals = DayPlans::withTargets($row->meals, Redistribution::targets($day, $chosen));
            $row->save();

            foreach ($chosen as $meal) {
                if ($meal->hasFoods) {
                    $state = MealPlanningState::query()->firstOrNew(['meal_id' => $meal->id]);
                    $state->forceFill(['user_id' => $user->id, 'state' => 'still_planning'])->save();
                }
            }
        });

        return $chosen;
    }
}
