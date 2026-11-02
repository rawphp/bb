<?php

namespace App\Capabilities\Exercises\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One row of the exercise library (EX-003, EX-013). `muscle_group` and
 * `equipment` are the filter keys; `detail` ("Chest · Barbell") and
 * `last_used_text` ("Last used 9 Oct 2026", "Never used") are what it shows.
 */
final class ExerciseLibraryRow extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $detail,
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle_group,
        #[Field(enum: DomainValues::EQUIPMENT)]
        public readonly string $equipment,
        public readonly string $last_used_text,
        public readonly bool $has_video,
    ) {}
}
