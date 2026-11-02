<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Domain\Model\Priority;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A priority the picker offers (MESO-020, MESO-030). Off is offered only on a
 * running mesocycle (MESO-034).
 */
final class MesocyclePriorityOption extends CapabilityData
{
    public function __construct(
        #[Field(enum: [Priority::Emphasize->value, Priority::Grow->value, Priority::Maintain->value, 'off'])]
        public readonly string $priority,
        public readonly string $title,
    ) {}
}
