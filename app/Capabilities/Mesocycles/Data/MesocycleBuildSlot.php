<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A draft slot: muscle, sets and the exercise picked, if any (MESO-013, MESO-014).
 */
final class MesocycleBuildSlot extends CapabilityData
{
    public function __construct(
        public readonly string $slot_id,
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle,
        public readonly ?string $exercise_id,
        #[Field(minimum: 1, maximum: 20)]
        public readonly int $sets,
    ) {}
}
