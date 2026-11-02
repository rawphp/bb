<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * An exercise a slot's drop-down offers (MESO-013).
 */
final class MesocycleExerciseOption extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
