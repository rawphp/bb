<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Working sets of one muscle in a week (MESO-040).
 */
final class MesocycleMuscleSets extends CapabilityData
{
    public function __construct(
        public readonly string $muscle_title,
        public readonly int $sets,
    ) {}
}
