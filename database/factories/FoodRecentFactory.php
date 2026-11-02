<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\FoodRecent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FoodRecent> */
class FoodRecentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'food_id' => Food::factory(),
            'used_at' => '2026-10-13 12:00:00',
        ];
    }
}
