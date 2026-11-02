<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Domain\Model\MuscleGroup;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A draft slot: muscle, sets and the exercise picked, if any (MESO-013, MESO-014).
 */
final class MesocycleBuildSlot extends CapabilityData
{
    public function __construct(
        public readonly string $slot_id,
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle,
        public readonly ?string $exercise_id,
        #[Field(minimum: 1, maximum: 20)]
        public readonly int $sets,
    ) {}
}
