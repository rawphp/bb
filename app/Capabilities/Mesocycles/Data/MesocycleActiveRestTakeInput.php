<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * "Take an active rest" (MESO-064): the deload week plus a week off before the next
 * mesocycle.
 */
final class MesocycleActiveRestTakeInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
    ) {}
}
