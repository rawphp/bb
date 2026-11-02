<?php

namespace Database\Factories;

use App\Models\DayTargetMeal;
use App\Models\MealPlanningState;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlanningState> */
class MealPlanningStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_id' => DayTargetMeal::factory(),
            'user_id' => fn (array $attributes) => DayTargetMeal::findOrFail($attributes['meal_id'])->user_id,
            'state' => 'still_planning',
        ];
    }
}
