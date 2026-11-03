<?php

namespace App\Actions\Today;

use App\Actions\Foods\MealFitFoods;
use App\Domain\Copy\CopyMeal;
use App\Domain\Copy\CopyOptions;
use App\Domain\Copy\CopyPlanner;
use App\Domain\Copy\CopyWrite;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Recipe\RecipeServing;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Plan;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use App\Support\Today\DayPlans;
use App\Support\Today\TodaySnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MEAL-060: copies each pair's source meal into its destination meal, as {@see CopyPlanner} decides, replacing the
 * destination's planned foods and recipe servings. Every row is removed and created through its model, and the meal
 * times through the day's target, in one transaction. Meals that are not the user's, or whose day is gone, are left out,
 * and so is a destination on a day outside the user's plan ({@see self::inPlan()}). The meals, their check-ins, planned
 * foods and recipe servings are read under their rows' lock in that transaction, and the copy is planned from them, so
 * a check-in or a food added meanwhile waits for the copy to commit or is seen by it: a destination checked in since
 * the Copy sheet opened is skipped, never overwritten. The locked reads are current reads, so this holds inside
 * UndoLog's transaction too. The planned rows are locked by meal, which takes an empty meal's index gap: two copies into
 * empty meals in one gap can deadlock, and the database then rolls one of them back whole.
 */
final class CopyMeals
{
    public function __construct(private readonly CopyPlanner $planner) {}

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  source meal id, destination meal id
     * @return list<DayTargetMeal> the destination meals written, in the order of their pairs, with their day
     */
    public function __invoke(User $user, array $pairs, CopyOptions $options): array
    {
        // DayTarget stores meal ids lowercased; a client may send a ULID in capitals.
        $pairs = array_map(fn (array $pair) => [strtolower($pair[0]), strtolower($pair[1])], $pairs);

        return DB::transaction(function () use ($user, $pairs, $options): array {
            $locked = fn ($query) => $query->lockForUpdate();
            $rows = DayTargetMeal::query()->liveOf($user)
                ->whereIn('id', array_values(array_unique(array_merge([], ...$pairs))))
                ->with([
                    'dayTarget',
                    'plannedFoods' => $locked, 'plannedFoods.food',
                    'plannedRecipes' => $locked, 'plannedRecipes.sourceRecipe.ingredients.food',
                    'checkIn' => $locked,
                ])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (DayTargetMeal $meal) => strtolower((string) $meal->id));
            $meals = $rows->map(fn (DayTargetMeal $meal) => self::meal($meal));
            $date = fn (DayTargetMeal $meal): string => DayPlans::day($meal->dayTarget->date)->format('Y-m-d');
            $inPlan = self::inPlan($user, array_values(array_unique($rows->map($date)->all())));
            $takes = fn (string $id): bool => $rows->has($id) && in_array($date($rows[$id]), $inPlan, true);

            $writes = $this->planner->writes(array_values(array_filter(array_map(
                fn (array $pair) => $meals->has($pair[0]) && $takes($pair[1]) ? ['source' => $meals[$pair[0]], 'destination' => $meals[$pair[1]]] : null,
                $pairs,
            ))), $options);

            foreach ($writes as $write) {
                $this->replace($user, $rows[$write->sourceId], $rows[$write->destinationId], $write);
            }
            $this->retime($rows, $writes);

            return array_map(fn (CopyWrite $write) => $rows[$write->destinationId], $writes);
        });
    }

    /**
     * MEAL-060, SCH-034, SCH-035: the dates a copy may write to, those from the user's plan start on; none without a plan.
     *
     * @param  list<string>  $dates  Y-m-d
     * @return list<string>
     */
    public static function inPlan(User $user, array $dates): array
    {
        $start = Plan::query()->where('user_id', $user->id)->value('start_date');
        if ($start === null) {
            return [];
        }
        $planStart = DayPlans::day($start)->format('Y-m-d');

        return array_values(array_filter($dates, fn (string $date) => $date >= $planStart));
    }

    /** The destination's planned foods and recipe servings become the copies, numbered in the source's order. */
    private function replace(User $user, DayTargetMeal $source, DayTargetMeal $destination, CopyWrite $write): void
    {
        $destination->plannedFoods->each->delete();
        $destination->plannedRecipes->each->delete();
        $foods = $source->plannedFoods->keyBy(fn (PlannedFood $f) => "food:{$f->id}");
        $recipes = $source->plannedRecipes->keyBy(fn (PlannedRecipe $r) => "recipe:{$r->id}");

        foreach ($write->entries as $position => $entry) {
            if ($foods->has($entry->id)) {
                $food = $foods[$entry->id];
                PlannedFood::query()->create([
                    'user_id' => $user->id,
                    'meal_id' => $destination->id,
                    'food_id' => $food->food_id,
                    'position' => $position,
                    'grams' => $entry->grams,
                    'is_cooked' => $food->is_cooked,
                    'is_locked' => $entry->isLocked,
                    'unit' => $food->unit,
                ]);
            } else {
                $recipe = $recipes[$entry->id];
                PlannedRecipe::query()->create([
                    'user_id' => $user->id,
                    'meal_id' => $destination->id,
                    'recipe_id' => $recipe->recipe_id,
                    'position' => $position,
                    'servings' => RecipeServing::servings($entry),
                    'is_locked' => $entry->isLocked,
                    // RCP-021: the destination is not checked in, so its copy follows the recipe as it is now.
                    'recipe' => $recipe->sourceRecipe?->plannedCopy() ?? $recipe->recipe,
                ]);
            }
        }
    }

    /**
     * MEAL-060: the destinations' new times, one save of each day's target.
     *
     * @param  Collection<string, DayTargetMeal>  $rows
     * @param  list<CopyWrite>  $writes
     */
    private function retime(Collection $rows, array $writes): void
    {
        /** @var array<string, array{day: DayTarget, times: array<string, string>}> $days */
        $days = [];
        foreach ($writes as $write) {
            if ($write->time !== null) {
                $day = $rows[$write->destinationId]->dayTarget;
                $days[$day->id]['day'] = $day;
                $days[$day->id]['times'][$write->destinationId] = $write->time;
            }
        }
        foreach ($days as ['day' => $day, 'times' => $times]) {
            $day->meals = array_map(
                fn (array $meal) => isset($times[$meal['id']]) ? ['time' => $times[$meal['id']]] + $meal : $meal,
                $day->meals,
            );
            $day->save();
        }
    }

    /** The meal as the copy rules see it: its targets and name from its day, its entries in their planned order. */
    private static function meal(DayTargetMeal $row): CopyMeal
    {
        $target = [];
        $index = 0;
        foreach ($row->dayTarget->meals as $position => $meal) {
            if (strcasecmp((string) ($meal['id'] ?? ''), (string) $row->id) === 0) {
                [$target, $index] = [$meal, $position];
            }
        }
        $entries = [
            ...$row->plannedFoods->map(fn (PlannedFood $f) => [$f->position, new MealFood(
                id: "food:{$f->id}",
                food: MealFitFoods::of($f->food),
                grams: $f->grams,
                weighing: $f->is_cooked ? Weighing::Cooked : Weighing::Raw,
                isLocked: $f->is_locked,
            )]),
            ...$row->plannedRecipes->map(fn (PlannedRecipe $r) => [$r->position, RecipeServing::food(
                "recipe:{$r->id}", TodaySnapshot::recipeName($r), TodaySnapshot::perServing($r), $r->servings, $r->is_locked,
            )]),
        ];
        usort($entries, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return new CopyMeal(
            id: strtolower((string) $row->id),
            name: ($target['name'] ?? null) ?: 'Meal '.($index + 1),
            time: isset($target['time']) ? (string) $target['time'] : null,
            target: DayPlans::target($target),
            isCheckedIn: $row->checkIn !== null,
            entries: array_map(fn (array $entry) => $entry[1], $entries),
        );
    }
}
