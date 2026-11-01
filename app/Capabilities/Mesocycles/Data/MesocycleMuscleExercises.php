<?php

namespace App\Capabilities\Mesocycles\Data;

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
        #[Field(enum: ['chest', 'back', 'quads', 'hamstrings', 'glutes', 'side_delts', 'rear_delts', 'front_delts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'])]
        public readonly string $muscle,
        public readonly string $muscle_title,
        #[Field(items: MesocycleExerciseOption::class)]
        public readonly array $exercises,
    ) {}
}
