<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkoutSet> */
class WorkoutSetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workout_session_id' => WorkoutSession::factory(),
            'user_id' => fn (array $attributes) => WorkoutSession::findOrFail($attributes['workout_session_id'])->user_id,
            'exercise_id' => Exercise::factory(),
            // A block of the set's own session and exercise; pass one to put several sets under it.
            'workout_exercise_id' => fn (array $attributes) => WorkoutExercise::factory()->create([
                'workout_session_id' => $attributes['workout_session_id'],
                'exercise_id' => $attributes['exercise_id'],
            ])->getKey(),
            'position' => 1,
            'type' => 'regular',
            'variant' => 'straight',
            'target_weight_kg' => 80,
            'target_reps' => 10,
            'actual_weight_kg' => null,
            'actual_reps' => null,
            'is_logged' => false,
            'is_skipped' => false,
        ];
    }

    public function logged(float $weightKg = 80, int $reps = 10): static
    {
        return $this->state(fn () => ['actual_weight_kg' => $weightKg, 'actual_reps' => $reps, 'is_logged' => true]);
    }
}
