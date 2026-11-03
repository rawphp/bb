<?php

namespace Database\Factories;

use App\Models\ClearedCoachTurn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClearedCoachTurn> */
class ClearedCoachTurnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tokens' => 1_200,
            'created_at' => now(),
        ];
    }
}
