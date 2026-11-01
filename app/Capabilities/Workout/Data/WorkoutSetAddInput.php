<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Add a set (CAP-019, client ULID): below `after_set_id` from the set menu
 * (WO-020), or at the end of the exercise with a type from its menu (WO-022,
 * after_set_id null). The server starts it from the set above. Added sets
 * carry forward (PROG-019).
 */
final class WorkoutSetAddInput extends CapabilityData
{
    public function __construct(
        public readonly string $set_id,
        public readonly string $workout_exercise_id,
        public readonly ?string $after_set_id,
        #[Field(enum: ['regular_straight', 'regular_down', 'regular_ascending', 'myorep', 'myorep_match'])]
        public readonly string $set_type,
    ) {}

    public static function rules(): array
    {
        return ['set_id' => ['required', 'ulid'], 'workout_exercise_id' => ['required', 'ulid'], 'after_set_id' => ['nullable', 'ulid']];
    }
}
