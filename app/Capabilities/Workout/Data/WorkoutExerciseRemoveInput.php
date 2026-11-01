<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Remove an exercise and its sets from this session (WO-022).
 */
final class WorkoutExerciseRemoveInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_exercise_id,
    ) {}

    public static function rules(): array
    {
        return ['workout_exercise_id' => ['required', 'ulid']];
    }
}
