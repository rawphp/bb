<?php

namespace App\Capabilities\Exercises\Data;

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Create a custom exercise, or edit one the user made (EX-004, EX-005). A new
 * `exercise_id` is a client-made ULID (CAP-019); an existing one edits it.
 */
final class CustomExerciseSaveInput extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $name,
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle_group,
        #[Field(enum: [Equipment::Barbell->value, Equipment::EzBar->value, Equipment::TrapBar->value, Equipment::Dumbbell->value, Equipment::Kettlebell->value, Equipment::Machine->value, Equipment::Cable->value, Equipment::SmithMachine->value, Equipment::Bodyweight->value])]
        public readonly string $equipment,
        #[Field(enum: [ExerciseLoadType::Barbell->value, ExerciseLoadType::Dumbbell->value, ExerciseLoadType::Machine->value, ExerciseLoadType::Cable->value, ExerciseLoadType::Bodyweight->value, ExerciseLoadType::BodyweightPlusLoad->value, ExerciseLoadType::SmithMachine->value])]
        public readonly string $load_type,
    ) {}

    public static function rules(): array
    {
        return ['exercise_id' => ['ulid']];
    }
}
