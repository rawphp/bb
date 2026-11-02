<?php

namespace Database\Factories;

use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mesocycle> */
class MesocycleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phase_id' => null,
            'name' => 'Full body 3x',
            'weeks' => 5,
            'start_date' => '2026-10-12',
            'ended_on' => null,
            'is_draft' => false,
            'is_resensitization' => false,
            'days' => [['label' => 'Day 1', 'weekday' => 2, 'slots' => [['exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 3]]]],
            'muscle_priorities' => ['chest' => 'grow'],
        ];
    }
}
