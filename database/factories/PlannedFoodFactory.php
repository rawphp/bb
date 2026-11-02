<?php

namespace Database\Factories;

use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlannedFood> */
class PlannedFoodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_id' => DayTargetMeal::factory(),
            'user_id' => fn (array $attributes) => DayTargetMeal::findOrFail($attributes['meal_id'])->user_id,
            'food_id' => Food::factory(),
            'position' => 0,
            'grams' => 150.0,
            'is_cooked' => false,
            'is_locked' => false,
            'unit' => 'g',
        ];
    }
}
