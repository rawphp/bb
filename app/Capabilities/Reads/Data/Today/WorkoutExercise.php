<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An exercise of a workout card. */
final class WorkoutExercise extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        #[Field(description: '"4 sets"')] public readonly string $sets_text,
    ) {}
}
