<?php

namespace App\Capabilities\Exercises\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Create a custom exercise, or edit one the user made (EX-004, EX-005). A new
 * `exercise_id` is a client-made ULID (CAP-019); an existing one edits it.
 */
final class CustomExerciseSaveInput extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $name,
        #[Field(enum: ['chest', 'back', 'quads', 'hamstrings', 'glutes', 'sideDelts', 'rearDelts', 'frontDelts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'])]
        public readonly string $muscle_group,
        #[Field(enum: ['barbell', 'ezBar', 'trapBar', 'dumbbell', 'kettlebell', 'machine', 'cable', 'smithMachine', 'bodyweight'])]
        public readonly string $equipment,
        #[Field(enum: ['barbell', 'dumbbell', 'machine', 'cable', 'bodyweight', 'bodyweightPlusLoad', 'smithMachine'])]
        public readonly string $load_type,
    ) {}

    public static function rules(): array
    {
        return ['exercise_id' => ['required', 'ulid']];
    }
}
