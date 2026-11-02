<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * An exercise of a day with its planned sets (MESO-040).
 */
final class MesocycleDayExercise extends CapabilityData
{
    public function __construct(
        public readonly string $slot_id,
        public readonly string $name,
        public readonly int $sets,
    ) {}
}
