<?php

namespace Tests\Support;

use App\Domain\Model\DayType;
use App\Models\DayTarget;
use App\Models\MealCheckIn;
use App\Models\PlannedFood;
use App\Models\User;

/**
 * Rows for the Today sheets' tests: Wednesday 23 September 2026, a 2,600 kcal day of three meals, Breakfast 7:30,
 * Lunch 12:30 and Dinner 19:00. Meal ids are UUIDs, as the server makes them.
 */
final class TodaySeed
{
    public const BREAKFAST = '0199a3f0-0000-7000-8000-000000000001';

    public const LUNCH = '0199a3f0-0000-7000-8000-000000000002';

    public const DINNER = '0199a3f0-0000-7000-8000-000000000003';

    /** @param  array<string, mixed>  $attributes */
    public static function day(User $user, string $date = '2026-09-23', array $attributes = []): DayTarget
    {
        $suffix = substr(str_replace('-', '', $date), 2);

        return DayTarget::factory()->for($user)->create(array_replace([
            'date' => $date,
            'day_type' => DayType::Moderate,
            'kcal' => 2600.0, 'protein_g' => 170.0, 'fat_g' => 70.0, 'carbs_g' => 320.0,
            'meals' => [
                ['id' => self::id(self::BREAKFAST, $suffix), 'name' => 'Breakfast', 'time' => '07:30', 'kcal' => 700, 'protein_g' => 30, 'fat_g' => 20, 'carbs_g' => 100],
                ['id' => self::id(self::LUNCH, $suffix), 'name' => 'Lunch', 'time' => '12:30', 'kcal' => 780, 'protein_g' => 50, 'fat_g' => 25, 'carbs_g' => 90],
                ['id' => self::id(self::DINNER, $suffix), 'name' => 'Dinner', 'time' => '19:00', 'kcal' => 1120, 'protein_g' => 90, 'fat_g' => 25, 'carbs_g' => 130],
            ],
        ], $attributes));
    }

    /** The meal id of the seeded day: the constant on 2026-09-23, made unique for any other date. */
    public static function id(string $meal, string $suffix = '260923'): string
    {
        return $suffix === '260923' ? $meal : substr($meal, 0, 24).$suffix.substr($meal, 30);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function checkIn(string $mealId, array $attributes = []): MealCheckIn
    {
        return MealCheckIn::factory()->create(['meal_id' => $mealId] + $attributes);
    }

    public static function food(string $mealId, bool $locked = false): PlannedFood
    {
        return PlannedFood::factory()->create(['meal_id' => $mealId, 'is_locked' => $locked]);
    }
}
