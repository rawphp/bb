<?php

namespace App\Capabilities\Exercises\Data;

use App\Domain\Model\Equipment;
use App\Domain\Model\MuscleGroup;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One row of the exercise library (EX-003, EX-013). `muscle_group` and
 * `equipment` are the filter keys; `detail` ("Chest · Barbell") and
 * `last_used_text` ("Last used 9 Oct 2026", "Never used") are what it shows.
 */
final class ExerciseLibraryRow extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $detail,
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle_group,
        #[Field(enum: [Equipment::Barbell->value, Equipment::EzBar->value, Equipment::TrapBar->value, Equipment::Dumbbell->value, Equipment::Kettlebell->value, Equipment::Machine->value, Equipment::Cable->value, Equipment::SmithMachine->value, Equipment::Bodyweight->value])]
        public readonly string $equipment,
        public readonly string $last_used_text,
        public readonly bool $has_video,
    ) {}
}
