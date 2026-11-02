<?php

namespace Database\Factories;

use App\Models\DayTargetMeal;
use App\Models\MealCheckIn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealCheckIn> */
class MealCheckInFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_id' => DayTargetMeal::factory(),
            'user_id' => fn (array $attributes) => DayTargetMeal::findOrFail($attributes['meal_id'])->user_id,
            'time' => '07:45',
            'kcal' => 500.0,
            'protein_g' => 35.0,
            'fat_g' => 15.0,
            'carbs_g' => 55.0,
            'is_manual' => false,
        ];
    }
}
