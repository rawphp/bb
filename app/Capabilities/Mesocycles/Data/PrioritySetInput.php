<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Sets a muscle's priority on a draft (MESO-020) or a saved mesocycle, from the next
 * session (MESO-032). Off leaves the muscle out (MESO-034).
 */
final class PrioritySetInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle,
        #[Field(enum: [Priority::Emphasize->value, Priority::Grow->value, Priority::Maintain->value, 'off'])]
        public readonly string $priority,
    ) {}
}
