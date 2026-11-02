<?php

namespace Database\Factories;

use App\Models\NotificationPref;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationPref> */
class NotificationPrefFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'meal',
            'on' => true,
        ];
    }
}
