<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Move an exercise to a new place in the session (WO-022 Move up and Move down,
 * WO-031 Reorder, OFF-021). `position` counts from 1. `remaining_weeks` also
 * writes the new order to the day for the sessions not yet started (MESO-045).
 */
final class WorkoutExerciseReorderInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_exercise_id,
        #[Field(minimum: 1)]
        public readonly int $position,
        public readonly bool $remaining_weeks,
    ) {}

    public static function rules(): array
    {
        return ['workout_exercise_id' => ['ulid']];
    }
}
