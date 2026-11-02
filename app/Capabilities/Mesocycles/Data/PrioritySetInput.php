<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\ExplicitNulls;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Sets a muscle's priority on a draft (MESO-020) or a saved mesocycle, from the next
 * session (MESO-032). A null `priority` is Off, which leaves the muscle out
 * (MESO-034); the domain's Priority has no Off case, a muscle without one is off.
 */
final class PrioritySetInput extends CapabilityData
{
    use ExplicitNulls;

    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle,
        #[Field(enum: DomainValues::PRIORITIES)]
        public readonly ?string $priority,
    ) {}
}
