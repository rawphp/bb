<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Profile> */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->firstName(),
            'sex' => 'female',
            'birth_date' => '1990-04-12',
            'height_cm' => 168.0,
            'weight_kg' => 64.0,
            'body_fat_bucket' => 'low',
            'uses_glp1' => false,
            'experience' => 'intermediate',
            'weight_unit' => 'kg',
            'height_unit' => 'cm',
            'appearance' => 'system',
            'consumed_totals' => 'planned',
            'micro_plates' => false,
            'eating_window' => ['start' => '07:00', 'end' => '20:00'],
            'meal_count' => 5,
            'busy_periods' => [],
            'training_days' => [['weekday' => 2, 'start' => '17:30', 'minutes' => 60]],
            'weigh_in_days' => [['weekday' => 2, 'time' => '07:00'], ['weekday' => 5, 'time' => '07:00']],
            'step_plan' => [],
            'diet_filters' => [],
            'screening' => [],
            'training_only' => false,
        ];
    }
}
