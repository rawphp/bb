<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\ExplicitNulls;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One muscle's priority, shown as bars (MESO-030, MESO-033): Emphasize 3, Grow 2,
 * Maintain 1, Off 0 (MESO-034). `muscle` and `priority` are the values priority_set takes;
 * a null `priority` is Off.
 */
final class MesocyclePriorityRow extends CapabilityData
{
    use ExplicitNulls;

    public function __construct(
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle,
        public readonly string $muscle_title,
        #[Field(enum: DomainValues::PRIORITIES)]
        public readonly ?string $priority,
        public readonly string $priority_title,
        public readonly int $bars,
    ) {}
}
