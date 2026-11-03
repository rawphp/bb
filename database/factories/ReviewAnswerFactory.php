<?php

namespace Database\Factories;

use App\Models\ReviewAnswer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewAnswer> */
class ReviewAnswerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => '2026-10-05',
            'option' => 'recommendation',
            'own_kcal' => null,
            'deload_too' => null,
            'update_step_plan' => false,
            'moves' => [],
            'refusal' => null,
        ];
    }
}
