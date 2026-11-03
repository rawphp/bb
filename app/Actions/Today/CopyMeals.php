<?php

namespace App\Actions\Today;

use App\Actions\Foods\MealFitFoods;
use App\Domain\Copy\CopyMeal;
use App\Domain\Copy\CopyOptions;
use App\Domain\Copy\CopyPlanner;
use App\Domain\Copy\CopyWrite;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use App\Support\Today\TodaySnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MEAL-060: copies each pair's source meal into its destination meal, as {@see CopyPlanner} decides, replacing the
 * destination's planned foods and recipe servings. Every row is removed and created through its model, and the meal
 * times through the day's target, in one transaction. Meals that are not the user's, or whose day is gone, are left out.
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
        $rows = DayTargetMeal::query()->liveOf($user)
            ->whereIn('id', array_values(array_unique(array_merge([], ...$pairs))))
            ->with(['dayTarget', 'plannedFoods.food', 'plannedRecipes.sourceRecipe.ingredients.food', 'checkIn'])
            ->get()
            ->keyBy(fn (DayTargetMeal $meal) => strtolower((string) $meal->id));
        $meals = $rows->map(fn (DayTargetMeal $meal) => self::meal($meal));

        $writes = $this->planner->writes(array_values(array_filter(array_map(
            fn (array $pair) => $meals->has($pair[0]) && $meals->has($pair[1]) ? ['source' => $meals[$pair[0]], 'destination' => $meals[$pair[1]]] : null,
            $pairs,
        ))), $options);

        DB::transaction(function () use ($user, $rows, $writes) {
            foreach ($writes as $write) {
                $this->replace($user, $rows[$write->sourceId], $rows[$write->destinationId], $write);
            }
            $this->retime($rows, $writes);
        });

        return array_map(fn (CopyWrite $write) => $rows[$write->destinationId], $writes);
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
            target: new MealTarget(
                kcal: (float) ($target['kcal'] ?? 0),
                proteinG: (float) ($target['protein_g'] ?? 0),
                fatG: (float) ($target['fat_g'] ?? 0),
                carbsG: (float) ($target['carbs_g'] ?? 0),
            ),
            isCheckedIn: $row->checkIn !== null,
            entries: array_map(fn (array $entry) => $entry[1], $entries),
        );
    }
}
