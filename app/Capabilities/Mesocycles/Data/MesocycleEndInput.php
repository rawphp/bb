<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Ends a running mesocycle today (MESO-043); `diet_break` makes next plan week a
 * diet break (MESO-044).
 */
final class MesocycleEndInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly bool $diet_break = false,
    ) {}
}
