<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One muscle's priority, shown as bars (MESO-030, MESO-033): Emphasize 3, Grow 2,
 * Maintain 1, Off 0 (MESO-034). `muscle` and `priority` are the values priority_set takes.
 */
final class MesocyclePriorityRow extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['chest', 'back', 'quads', 'hamstrings', 'glutes', 'side_delts', 'rear_delts', 'front_delts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'])]
        public readonly string $muscle,
        public readonly string $muscle_title,
        #[Field(enum: ['emphasize', 'grow', 'maintain', 'off'])]
        public readonly string $priority,
        public readonly string $priority_title,
        public readonly int $bars,
    ) {}
}
