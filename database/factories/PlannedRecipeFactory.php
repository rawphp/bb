<?php

namespace Database\Factories;

use App\Models\DayTargetMeal;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlannedRecipe> */
class PlannedRecipeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_id' => DayTargetMeal::factory(),
            'user_id' => fn (array $attributes) => DayTargetMeal::findOrFail($attributes['meal_id'])->user_id,
            'recipe_id' => Recipe::factory(),
            'position' => 0,
            'servings' => 1.0,
            'is_locked' => false,
            'recipe' => fn (array $attributes) => Recipe::findOrFail($attributes['recipe_id'])->only(['id', 'name', 'servings', 'meal_type']),
        ];
    }
}
