<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A muscle slot of a draft day (MESO-013, MESO-014): its sets and the chosen
 * exercise, null until one is picked.
 */
final class MesocycleBuildSlotRow extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        #[Field(enum: ['chest', 'back', 'quads', 'hamstrings', 'glutes', 'side_delts', 'rear_delts', 'front_delts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'])]
        public readonly string $muscle,
        public readonly string $muscle_title,
        public readonly int $sets,
        public readonly string $sets_text,
        public readonly ?string $exercise_id,
        public readonly ?string $exercise_name,
    ) {}
}
