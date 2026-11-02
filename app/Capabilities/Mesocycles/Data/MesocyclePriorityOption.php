<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Support\AcceptsNullEnums;
use App\Domain\Model\Priority;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A priority the picker offers (MESO-020, MESO-030). Off is offered only on a
 * running mesocycle (MESO-034), as a null `priority`.
 */
final class MesocyclePriorityOption extends CapabilityData
{
    use AcceptsNullEnums;

    public function __construct(
        #[Field(enum: [Priority::Emphasize->value, Priority::Grow->value, Priority::Maintain->value])]
        public readonly ?string $priority,
        public readonly string $title,
    ) {}
}
