<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\ExerciseLoadStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExerciseLoadStep> */
class ExerciseLoadStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'exercise_id' => Exercise::factory(),
            'step_kg' => 2.5,
        ];
    }
}
