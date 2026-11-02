<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Input for mesocycle_show: the mesocycle, saved or draft.
 */
final class MesocycleShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
    ) {}
}
