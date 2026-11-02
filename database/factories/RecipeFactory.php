<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** A library recipe; use for($user) for a user's own. @extends Factory<Recipe> */
class RecipeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'slug' => null,
            'name' => fake()->unique()->words(3, true),
            'servings' => 4.0,
            'meal_type' => 'dinner',
            'instructions' => ['Cook everything.'],
            ...array_fill_keys(Food::DIETS, false),
        ];
    }
}
