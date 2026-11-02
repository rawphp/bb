<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Continue on the builder or scratch step: the server lays out the draft's days
 * from its days per week, empty for scratch, or with muscles on days and slots for the Meso
 * Builder (MESO-022, MESO-023).
 */
final class MesocycleStructureGenerateInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
    ) {}
}
