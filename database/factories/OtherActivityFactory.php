<?php

namespace Database\Factories;

use App\Models\OtherActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OtherActivity> */
class OtherActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-10-12',
            'start_time' => '07:00',
            'minutes' => 45,
            'name' => 'Run',
            'intensity' => 'moderate',
        ];
    }
}
