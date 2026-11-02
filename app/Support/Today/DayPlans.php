<?php

namespace App\Support\Today;

use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;
use App\Models\DayTarget;
use App\Models\MealCheckIn;
use App\Models\OffPlanEntry;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Reads a user's stored days for the Today rules: each day target with its meals' check-ins, planned foods and
 * recipes, and the day's off-plan food. The rows are kept, so a write saves the day it read.
 */
final class DayPlans
{
    /** @param  array<string, DayTarget>  $rows  keyed by Y-m-d */
    private function __construct(
        public readonly array $rows,
        /** @var array<string, DayPlan> */
        public readonly array $plans,
    ) {}

    /** @param  list<string>  $dates  Y-m-d */
    public static function load(User $user, array $dates): self
    {
        $rows = DayTarget::query()->where('user_id', $user->id)
            ->whereIn('date', array_values(array_unique($dates)))
            ->get()
            ->keyBy(fn (DayTarget $target) => $target->date->format('Y-m-d'));
        $mealIds = $rows->flatMap(fn (DayTarget $target) => array_column($target->meals, 'id'))->all();
        $checkIns = MealCheckIn::query()->whereIn('meal_id', $mealIds)->get()->keyBy('meal_id');
        $foods = PlannedFood::query()->whereIn('meal_id', $mealIds)->get(['meal_id', 'is_locked'])
            ->concat(PlannedRecipe::query()->whereIn('meal_id', $mealIds)->get(['meal_id', 'is_locked']))
            ->groupBy('meal_id');
        $offPlan = OffPlanEntry::query()->where('user_id', $user->id)
            ->whereIn('date', $rows->keys()->all())
            ->get()
            ->groupBy(fn (OffPlanEntry $entry) => $entry->date->format('Y-m-d'));

        $plans = [];
        foreach ($rows as $date => $row) {
            $plans[$date] = self::build($row, $checkIns, $foods, $offPlan->get($date, new Collection));
        }

        return new self($rows->all(), $plans);
    }

    public function plan(string $date): ?DayPlan
    {
        return $this->plans[$date] ?? null;
    }

    public function row(string $date): ?DayTarget
    {
        return $this->rows[$date] ?? null;
    }

    /** The day at midnight, read from its Y-m-d in UTC, as dates are compared whole. */
    public static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($date, 0, 10), new DateTimeZone('UTC'));
    }

    /**
     * @param  Collection<string, MealCheckIn>  $checkIns
     * @param  Collection<string, Collection<int, PlannedFood|PlannedRecipe>>  $foods
     * @param  Collection<int, OffPlanEntry>  $offPlan
     */
    private static function build(DayTarget $row, Collection $checkIns, Collection $foods, Collection $offPlan): DayPlan
    {
        $meals = array_map(function (array $meal) use ($checkIns, $foods) {
            $checkIn = $checkIns->get($meal['id']);
            $planned = $foods->get($meal['id'], new Collection);

            return new DayMeal(
                id: $meal['id'],
                name: ($meal['name'] ?? null) ?: 'Meal',
                time: isset($meal['time']) ? ClockTime::fromHhmm((string) $meal['time']) : null,
                target: self::target($meal),
                eaten: $checkIn === null ? null : new MealTarget($checkIn->kcal, $checkIn->protein_g, $checkIn->fat_g, $checkIn->carbs_g),
                hasFoods: $planned->isNotEmpty(),
                onlyLocked: $planned->isNotEmpty() && $planned->every(fn (PlannedFood|PlannedRecipe $item) => (bool) $item->is_locked),
            );
        }, array_values($row->meals));

        return new DayPlan(
            date: self::day($row->date->format('Y-m-d')),
            type: $row->day_type,
            target: new MealTarget($row->kcal, $row->protein_g, $row->fat_g, $row->carbs_g),
            meals: $meals,
            offPlan: $offPlan->map(fn (OffPlanEntry $entry) => new MealTarget($entry->kcal, $entry->protein_g, $entry->fat_g, $entry->carbs_g))->values()->all(),
        );
    }

    /** @param  array<string, mixed>  $meal */
    public static function target(array $meal): MealTarget
    {
        return new MealTarget(
            kcal: (float) ($meal['kcal'] ?? 0),
            proteinG: (float) ($meal['protein_g'] ?? 0),
            fatG: (float) ($meal['fat_g'] ?? 0),
            carbsG: (float) ($meal['carbs_g'] ?? 0),
        );
    }

    /**
     * The stored meals with these targets in place of their own; the other meals and every other key stay.
     *
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, MealTarget>  $targets  by meal id
     * @return list<array<string, mixed>>
     */
    public static function withTargets(array $meals, array $targets): array
    {
        return array_map(function (array $meal) use ($targets) {
            $target = $targets[$meal['id']] ?? null;

            return $target === null ? $meal : array_replace($meal, [
                'kcal' => $target->kcal, 'protein_g' => $target->proteinG, 'fat_g' => $target->fatG, 'carbs_g' => $target->carbsG,
            ]);
        }, array_values($meals));
    }
}
