<?php

namespace App\Support\Plan;

use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;

/**
 * A built day as its `day_targets` columns (NUT-030): the date, the day type, the totals and each meal under the id it
 * is saved with, its time as "HH:MM". The totals are null for a training-only day (SAFE-003). Every writer of a built
 * week saves this shape: "Start my plan" and the rebuild after a profile change.
 */
final class DayTargetRow
{
    /** @return array{date: string, day_type: string, kcal: ?float, protein_g: ?float, fat_g: ?float, carbs_g: ?float, meals: list<array{id: string, name: string, time: string, kcal: float, protein_g: float, fat_g: float, carbs_g: float}>} */
    public static function from(WeekDay $day): array
    {
        return [
            'date' => $day->day->date->format('Y-m-d'),
            'day_type' => $day->day->type->value,
            'kcal' => $day->target?->kcal,
            'protein_g' => $day->target?->proteinG,
            'fat_g' => $day->target?->fatG,
            'carbs_g' => $day->target?->carbsG,
            'meals' => array_map(fn (WeekMeal $meal) => [
                'id' => $meal->id(),
                'name' => $meal->meal->name,
                'time' => (string) $meal->time,
                'kcal' => $meal->meal->target->kcal,
                'protein_g' => $meal->meal->target->proteinG,
                'fat_g' => $meal->meal->target->fatG,
                'carbs_g' => $meal->meal->target->carbsG,
            ], $day->meals),
        ];
    }
}
