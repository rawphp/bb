<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Domain\Model\MuscleGroup;
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
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle,
        public readonly string $muscle_title,
        public readonly int $sets,
        public readonly string $sets_text,
        public readonly ?string $exercise_id,
        public readonly ?string $exercise_name,
    ) {}
}
