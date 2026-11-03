<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The workout screen (WO-001 to WO-052, NAV-004) and everything the session
 * needs offline (OFF-020): every set's target and rep table (CAP-016), the
 * feedback sheets and when they fall due (FB-001 to FB-008), the notes and the
 * next-meal line (WO-009).
 *
 * `workout_id` is stable before the session starts, so writes queued offline
 * can name it. `can_finish` and the banners are decided at read time; while
 * writes are pending the view shows the user's own input (OFF-009) and
 * workout_finish has the last word.
 */
final class WorkoutShowOutput extends CapabilityData
{
    /**
     * @param  list<WorkoutExercise>  $exercises
     * @param  list<WorkoutFeedback>  $feedback
     * @param  list<WorkoutChoice>  $set_type_choices
     * @param  list<WorkoutChoice>  $add_set_choices
     * @param  list<WorkoutHelpSection>  $help
     * @param  list<WorkoutLookBackRow>  $look_back
     */
    public function __construct(
        public readonly string $workout_id,
        public readonly string $mesocycle_id,
        /** "Week 3 · Day 1 · Upper A · Mon 28 Sep" (WO-001). */
        public readonly string $title,
        /** "Upper A", what the Relabel sheet starts from (WO-031). */
        public readonly string $day_label,
        #[Field(enum: ['not_started', 'in_progress', 'finished', 'skipped'])]
        public readonly string $status,
        /** False for a finished or skipped session and for look back. */
        public readonly bool $is_editable,
        /** NAV-004 "Upper A · 1 of 2 exercises"; null unless in progress. */
        public readonly ?string $active_bar_text,
        /** The unit every weight is shown in and every write is tagged with (CAP-015). */
        #[Field(enum: DomainValues::WEIGHT_UNITS)]
        public readonly string $weight_unit,
        /** PROG-043, deload weeks only. */
        public readonly ?Status $deload_banner,
        /** WO-040 "Your feedback is required" while unanswered feedback blocks Finish. */
        public readonly ?Status $feedback_banner,
        /** WO-040. */
        public readonly bool $can_finish,
        /** WO-009 "Next: Meal 3 at 7:00 PM · 95 g carbs". */
        public readonly ?string $next_meal,
        /** WO-024, on the first exercise until a set is done. */
        public readonly ?WorkoutWarmUp $warm_up,
        /** WO-007: what the info icon on reps says this week. */
        public readonly WorkoutHelpSection $rir_info,
        #[Field(items: WorkoutExercise::class)]
        public readonly array $exercises,
        #[Field(items: WorkoutFeedback::class)]
        public readonly array $feedback,
        /** WO-020, WO-021: the Set type list of the set menu. */
        #[Field(items: WorkoutChoice::class)]
        public readonly array $set_type_choices,
        /** WO-022: the exercise menu's Add set choices. */
        #[Field(items: WorkoutChoice::class)]
        public readonly array $add_set_choices,
        /** The ? sheet (WO-023, WO-024, WO-030, WO-052, PROG-043). */
        #[Field(items: WorkoutHelpSection::class)]
        public readonly array $help,
        /** EX-008, shown on the Swap sheet. */
        public readonly string $swap_advice,
        /** WO-008: earlier sessions of the mesocycle, newest first. */
        #[Field(items: WorkoutLookBackRow::class)]
        public readonly array $look_back,
    ) {}
}
