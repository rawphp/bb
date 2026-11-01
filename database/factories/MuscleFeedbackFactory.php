<?php

namespace Database\Factories;

use App\Domain\Model\MuscleGroup;
use App\Models\MuscleFeedback;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MuscleFeedback> */
class MuscleFeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workout_session_id' => WorkoutSession::factory(),
            'user_id' => fn (array $attributes) => WorkoutSession::findOrFail($attributes['workout_session_id'])->user_id,
            'muscle_group' => MuscleGroup::Chest,
            'soreness' => 'healed_just_on_time',
            'pump' => 'moderate',
            'volume' => 'just_right',
        ];
    }
}
