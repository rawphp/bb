<?php

namespace Database\Factories;

use App\Domain\Model\DayType;
use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DayTarget> */
class DayTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-10-13',
            'day_type' => DayType::Rest,
            'kcal' => 2000.0,
            'protein_g' => 140.0,
            'fat_g' => 60.0,
            'carbs_g' => 220.0,
            'meals' => [['name' => 'Breakfast', 'time' => '07:30', 'kcal' => 500, 'protein_g' => 35, 'fat_g' => 15, 'carbs_g' => 55]],
        ];
    }
}
