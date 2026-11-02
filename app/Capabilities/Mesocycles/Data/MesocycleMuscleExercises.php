<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Domain\Model\MuscleGroup;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The exercises for one muscle, by name: the slot drop-down and the add-exercise
 * muscle list (MESO-013, MESO-014).
 */
final class MesocycleMuscleExercises extends CapabilityData
{
    /**
     * @param  list<MesocycleExerciseOption>  $exercises
     */
    public function __construct(
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle,
        public readonly string $muscle_title,
        #[Field(items: MesocycleExerciseOption::class)]
        public readonly array $exercises,
    ) {}
}
