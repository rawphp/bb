<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Sets a muscle's priority on a draft (MESO-020) or a saved mesocycle, from the next
 * session (MESO-032). Off leaves the muscle out (MESO-034).
 */
final class PrioritySetInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(enum: ['chest', 'back', 'quads', 'hamstrings', 'glutes', 'side_delts', 'rear_delts', 'front_delts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'])]
        public readonly string $muscle,
        #[Field(enum: ['emphasize', 'grow', 'maintain', 'off'])]
        public readonly string $priority,
    ) {}
}
