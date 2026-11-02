<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WeighInScheduleEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WeighInScheduleEntry> */
class WeighInScheduleEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'weekdays' => [2, 5],
            'time' => '07:00',
        ];
    }
}
