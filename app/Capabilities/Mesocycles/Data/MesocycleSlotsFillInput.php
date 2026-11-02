<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * "Pick for me": fills every empty slot of the draft with the best-rated exercise
 * for its muscle, preferring high stimulus-to-fatigue in fat loss (MESO-013, MESO-016).
 */
final class MesocycleSlotsFillInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
    ) {}
}
