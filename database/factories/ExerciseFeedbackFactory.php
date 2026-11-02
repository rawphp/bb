<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExerciseFeedback> */
class ExerciseFeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workout_session_id' => WorkoutSession::factory(),
            'user_id' => fn (array $attributes) => WorkoutSession::findOrFail($attributes['workout_session_id'])->user_id,
            'exercise_id' => Exercise::factory(),
            'joint_pain' => 'none',
        ];
    }
}
