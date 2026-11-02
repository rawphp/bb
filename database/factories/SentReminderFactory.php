<?php

namespace Database\Factories;

use App\Models\SentReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SentReminder> */
class SentReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reminder_id' => 'weigh-in-'.fake()->unique()->date(),
            'due_at' => now(),
            'sent_at' => now(),
        ];
    }
}
