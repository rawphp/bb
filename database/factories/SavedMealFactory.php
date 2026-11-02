<?php

namespace Database\Factories;

use App\Models\SavedMeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedMeal> */
class SavedMealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Usual breakfast',
        ];
    }
}
