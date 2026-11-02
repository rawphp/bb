<?php

namespace Database\Factories;

use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanWeek> */
class PlanWeekFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'start_date' => '2026-10-12',
            'is_deload' => false,
            'is_diet_break' => false,
            'diet_break_off' => false,
            'is_away' => false,
            'away_training' => null,
            'away_calories' => null,
            'reviewed_at' => null,
        ];
    }
}
