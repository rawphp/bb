<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Add a library exercise to the end of the session with three regular sets
 * (WO-031; CAP-019, client ULID for the block). `remaining_weeks` adds it to
 * the day for the sessions not yet started too (MESO-045). Needs the network
 * (OFF-021).
 */
final class WorkoutExerciseAddInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_id,
        public readonly string $workout_exercise_id,
        public readonly string $exercise_id,
        public readonly bool $remaining_weeks,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['ulid'], 'workout_exercise_id' => ['ulid']];
    }
}
