<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * This session's note on an exercise (WO-022 Add note, OFF-021 "edit a note").
 * A null note clears it. The pinned note that shows every time is
 * exercise_note_set (EX-010).
 */
final class WorkoutExerciseNoteSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_exercise_id,
        #[Field(minLength: 1, maxLength: 1000)]
        public readonly ?string $note,
    ) {}

    public static function rules(): array
    {
        return ['workout_exercise_id' => ['required', 'ulid']];
    }
}
