<?php

namespace Database\Factories;

use App\Models\ReminderMorning;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReminderMorning> */
class ReminderMorningFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => fake()->unique()->date(),
            'judged_at' => now(),
        ];
    }
}
