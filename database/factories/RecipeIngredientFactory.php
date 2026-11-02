<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeIngredient> */
class RecipeIngredientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'food_id' => Food::factory(),
            'position' => 0,
            'grams' => 500.0,
            'is_cooked' => false,
        ];
    }
}
