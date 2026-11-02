<?php

namespace Database\Factories;

use App\Models\PendingSignInCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<PendingSignInCode> */
class PendingSignInCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => Hash::make('123456'),
            'failed_tries' => 0,
            'issued_at' => now(),
        ];
    }
}
