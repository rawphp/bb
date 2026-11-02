<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FoodFavorite> */
class FoodFavoriteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'food_id' => Food::factory(),
        ];
    }
}
