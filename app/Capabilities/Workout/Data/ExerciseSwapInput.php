<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Swap an exercise in a session for another of the same muscle, for this session
 * only or for the rest of the mesocycle (EX-008). Sets already done stay on the
 * old exercise; the rest move to the new one and take its targets. Needs the
 * network (OFF-021). The new block in this session takes
 * `new_workout_exercise_id`, a client-made ULID (CAP-019), so a retried swap
 * with the same ID changes nothing; later sessions' blocks get server IDs.
 */
final class ExerciseSwapInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_exercise_id,
        public readonly string $new_workout_exercise_id,
        public readonly string $exercise_id,
        #[Field(enum: ['this_session', 'rest_of_mesocycle'])]
        public readonly string $scope,
    ) {}

    public static function rules(): array
    {
        return ['workout_exercise_id' => ['ulid'], 'new_workout_exercise_id' => ['ulid']];
    }
}
