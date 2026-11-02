<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Support\ExplicitNulls;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One muscle's priority, shown as bars (MESO-030, MESO-033): Emphasize 3, Grow 2,
 * Maintain 1, Off 0 (MESO-034). `muscle` and `priority` are the values priority_set takes;
 * a null `priority` is Off.
 */
final class MesocyclePriorityRow extends CapabilityData
{
    use ExplicitNulls;

    public function __construct(
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle,
        public readonly string $muscle_title,
        #[Field(enum: [Priority::Emphasize->value, Priority::Grow->value, Priority::Maintain->value])]
        public readonly ?string $priority,
        public readonly string $priority_title,
        public readonly int $bars,
    ) {}
}
