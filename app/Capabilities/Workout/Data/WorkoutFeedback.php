<?php

namespace App\Capabilities\Workout\Data;

use App\Domain\Model\MuscleGroup;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The Feedback sheet of one muscle (FB-001 to FB-009). It falls due once every
 * set of `due_after_exercise_id` (the muscle's last exercise in the session)
 * is logged or skipped; `is_due` is that answer at read time. Deload weeks
 * send no sheets (FB-008); soreness is left out when not asked (FB-007).
 */
final class WorkoutFeedback extends CapabilityData
{
    /**
     * @param  list<WorkoutFeedbackQuestion>  $questions
     */
    public function __construct(
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle_group,
        /** "Chest". */
        public readonly string $title,
        public readonly string $due_after_exercise_id,
        public readonly bool $is_due,
        public readonly bool $is_answered,
        /** FB-009: false once the muscle has trained in a later session. */
        public readonly bool $can_edit,
        #[Field(items: WorkoutFeedbackQuestion::class)]
        public readonly array $questions,
    ) {}
}
