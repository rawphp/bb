<?php

namespace Database\Factories;

use App\Models\ShoppingTicks;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShoppingTicks> */
class ShoppingTicksFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-08',
            'keys' => ['food:01jbx5f0000000000000000002'],
        ];
    }
}
