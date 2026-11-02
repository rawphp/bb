<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\FoodServing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FoodServing> */
class FoodServingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'food_id' => Food::factory(),
            'label' => '1 breast',
            'grams' => 174.0,
        ];
    }
}
