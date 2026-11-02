<?php

namespace Database\Factories;

use App\Models\UndoToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UndoToken> */
class UndoTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'changes' => [],
            'invalidates' => [],
            'expires_at' => now()->addDay(),
        ];
    }
}
