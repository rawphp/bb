<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A workout block between destination meals (MEAL-060). */
final class CopyWorkout extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        #[Field(description: '"5:30 PM · 55 min"')] public readonly string $time_text,
    ) {}
}
