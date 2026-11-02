<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkoutExercise> */
class WorkoutExerciseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workout_session_id' => WorkoutSession::factory(),
            'user_id' => fn (array $attributes) => WorkoutSession::findOrFail($attributes['workout_session_id'])->user_id,
            'exercise_id' => Exercise::factory(),
            'position' => 1,
            'note' => null,
        ];
    }
}
