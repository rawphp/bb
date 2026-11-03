<?php

namespace App\Support\Plan;

use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;

/**
 * A built day as its `day_targets` columns (NUT-030): the date, the day type, the totals and each meal under the id it
 * is saved with, its time as "HH:MM". The totals are null for a training-only day (SAFE-003). Every writer of a built
 * week saves this shape: "Start my plan" and the rebuild after a profile change. The re-target writes the same totals
 * and meal entries onto a stored row through {@see self::totals()} and {@see self::meal()}.
 */
final class DayTargetRow
{
    /** @return array{date: string, day_type: string, kcal: ?float, protein_g: ?float, fat_g: ?float, carbs_g: ?float, meals: list<array{id: string, name: string, time: string, kcal: float, protein_g: float, fat_g: float, carbs_g: float}>} */
    public static function from(WeekDay $day): array
    {
        return [
            'date' => $day->day->date->format('Y-m-d'),
            'day_type' => $day->day->type->value,
            ...self::totals($day->target),
            'meals' => array_map(fn (WeekMeal $meal) => self::meal($meal->meal, $meal->time), $day->meals),
        ];
    }

    /**
     * A meal as its entry in the row's `meals` column, under the id it is saved with.
     *
     * @return array{id: string, name: string, time: string, kcal: float, protein_g: float, fat_g: float, carbs_g: float}
     */
    public static function meal(Meal $meal, ClockTime $time): array
    {
        return [
            'id' => $meal->id,
            'name' => $meal->name,
            'time' => (string) $time,
            'kcal' => $meal->target->kcal,
            'protein_g' => $meal->target->proteinG,
            'fat_g' => $meal->target->fatG,
            'carbs_g' => $meal->target->carbsG,
        ];
    }

    /**
     * The day's totals as their columns, each null when the day has no target (SAFE-003).
     *
     * @return array{kcal: ?float, protein_g: ?float, fat_g: ?float, carbs_g: ?float}
     */
    public static function totals(?MealTarget $target): array
    {
        return [
            'kcal' => $target?->kcal,
            'protein_g' => $target?->proteinG,
            'fat_g' => $target?->fatG,
            'carbs_g' => $target?->carbsG,
        ];
    }
}
