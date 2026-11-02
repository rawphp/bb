<?php

namespace App\Capabilities\Exercises\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\ExplicitNulls;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Create a custom exercise, or edit one the user made (EX-004, EX-005).
 *
 * This is the one create that takes no client-made ULID (the CAP-019 exception):
 * exercise ids are integers across the training tables, so a create sends no
 * `exercise_id` (or null) and the server assigns it. An edit sends the integer
 * id of one of the user's own custom exercises, written as a string of digits,
 * the way exercises_show returns it.
 */
final class CustomExerciseSaveInput extends CapabilityData
{
    use ExplicitNulls;

    public function __construct(
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $name,
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle_group,
        #[Field(enum: DomainValues::EQUIPMENT)]
        public readonly string $equipment,
        #[Field(enum: DomainValues::LOAD_TYPES)]
        public readonly string $load_type,
        public readonly ?string $exercise_id = null,
    ) {}

    public static function rules(): array
    {
        // The id is typed as a string, so the package's portable `integer` rule would never run (it counts on the
        // schema); not_regex does. Digits and nothing else: an integer id, as a string.
        return ['exercise_id' => ['nullable', 'not_regex:/\\D/']];
    }
}
