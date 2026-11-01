<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A weight as the user typed it, tagged with its unit (CAP-015). The server
 * converts it for storage.
 */
final class WorkoutWeight extends CapabilityData
{
    public function __construct(
        public readonly float $value,
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $unit,
    ) {}
}
