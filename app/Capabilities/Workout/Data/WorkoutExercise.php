<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Train\Data\Pill;
use App\Domain\Model\MuscleGroup;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One exercise of the session with its sets (WO-002). `id` is this block in
 * the session (one library exercise can appear twice after a swap);
 * `exercise_id` is the library exercise, for history, the pinned note
 * (exercise_note_set) and the swap picker's muscle filter.
 */
final class WorkoutExercise extends CapabilityData
{
    /**
     * @param  list<WorkoutSet>  $sets
     */
    public function __construct(
        public readonly string $id,
        public readonly string $exercise_id,
        public readonly string $name,
        /** "Barbell · 5–10 reps". */
        public readonly string $detail,
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle_group,
        /** WO-023 "Weight of one dumbbell (kg)", "Weight (kg)". */
        public readonly string $weight_label,
        /** EX-010. */
        public readonly ?string $pinned_note,
        /** WO-022: this session's note. */
        public readonly ?string $session_note,
        /** PROG-051, PROG-053, PROG-018: why this week's targets moved. */
        public readonly ?Pill $recovery_note,
        /** EX-009 "Barbell Row hurt 2 sessions running. Consider swapping it." */
        public readonly ?Pill $swap_suggestion,
        /** WO-006: a weight typed on set 1 fills the empty weight fields below it. */
        public readonly bool $carries_first_weight,
        public readonly bool $can_move_up,
        public readonly bool $can_move_down,
        #[Field(items: WorkoutSet::class)]
        public readonly array $sets,
    ) {}
}
