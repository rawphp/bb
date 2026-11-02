<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Train\Data\Pill;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The create flow's state for a draft (MESO-003 to MESO-007, MESO-013, MESO-014,
 * MESO-020 to MESO-023). `resume_step` is where the flow opens. Steppers offer only the server's
 * options; `warnings` are MESO-021's, which never block.
 */
final class MesocycleBuild extends CapabilityData
{
    /**
     * @param  list<MesocycleOption>  $weeks_options
     * @param  list<MesocycleOption>  $days_per_week_options
     * @param  list<MesocyclePriorityRow>  $priorities
     * @param  list<Pill>  $warnings
     * @param  list<MesocycleBuildDayRow>  $days
     * @param  list<MesocycleOption>  $sets_options
     * @param  list<MesocycleMuscleExercises>  $exercise_options
     */
    public function __construct(
        #[Field(enum: ['template', 'copy_previous', 'builder', 'scratch'])]
        public readonly string $route,
        #[Field(enum: ['builder', 'scratch', 'slots', 'details'])]
        public readonly string $resume_step,
        public readonly int $weeks,
        #[Field(items: MesocycleOption::class)]
        public readonly array $weeks_options,
        public readonly string $start_date,
        public readonly string $start_text,
        #[Field(enum: ['weekdays', 'floating'])]
        public readonly string $label_style,
        public readonly bool $weekdays_available,
        public readonly ?string $phase_title,
        public readonly ?string $rep_range_text,
        public readonly ?Pill $phase_warning,
        public readonly int $days_per_week,
        #[Field(items: MesocycleOption::class)]
        public readonly array $days_per_week_options,
        #[Field(items: MesocyclePriorityRow::class)]
        public readonly array $priorities,
        #[Field(items: Pill::class)]
        public readonly array $warnings,
        public readonly ?Pill $priority_notice,
        #[Field(items: MesocycleBuildDayRow::class)]
        public readonly array $days,
        #[Field(items: MesocycleOption::class)]
        public readonly array $sets_options,
        #[Field(items: MesocycleMuscleExercises::class)]
        public readonly array $exercise_options,
        public readonly bool $can_pick_for_me,
        public readonly bool $can_finish,
    ) {}
}
