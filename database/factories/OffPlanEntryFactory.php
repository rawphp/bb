<?php

namespace Database\Factories;

use App\Models\OffPlanEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OffPlanEntry> */
class OffPlanEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-10-13',
            'name' => 'Birthday cake',
            'time' => '15:00',
            'food_id' => null,
            'kcal' => 350.0,
            'protein_g' => 4.0,
            'fat_g' => 18.0,
            'carbs_g' => 45.0,
        ];
    }
}
