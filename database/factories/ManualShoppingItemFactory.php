<?php

namespace Database\Factories;

use App\Models\ManualShoppingItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ManualShoppingItem> */
class ManualShoppingItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'text' => 'Paper towels',
        ];
    }
}
