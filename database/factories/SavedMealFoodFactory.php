<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedMealFood> */
class SavedMealFoodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'saved_meal_id' => SavedMeal::factory(),
            'food_id' => Food::factory(),
            'position' => 0,
            'grams' => 100.0,
            'is_cooked' => false,
            'is_locked' => false,
            'unit' => 'g',
        ];
    }
}
