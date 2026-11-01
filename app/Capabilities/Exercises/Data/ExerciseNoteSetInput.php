<?php

namespace App\Capabilities\Exercises\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Pin a note to an exercise, shown every time it appears (EX-010). A null
 * note clears it.
 */
final class ExerciseNoteSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_id,
        #[Field(minLength: 1, maxLength: 1000)]
        public readonly ?string $note,
    ) {}
}
