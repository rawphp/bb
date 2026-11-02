<?php

namespace App\Capabilities\Exercises\Data;

use App\Capabilities\Support\DomainValues;
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
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle_group,
        #[Field(enum: DomainValues::EQUIPMENT)]
        public readonly string $equipment,
        #[Field(enum: DomainValues::LOAD_TYPES)]
        public readonly string $load_type,
    ) {}

    public static function rules(): array
    {
        return ['exercise_id' => ['ulid']];
    }
}
