<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One next step on the transition screen: a phase type, or Gain first after
 * Fat loss (PH-032), which is `choice` muscleGain with `gain_first` true: its
 * first phase is Muscle gain. The recommendation comes first (PH-031, PH-033,
 * PH-034).
 *
 * `lengths` are the lengths allowed (PH-031 for Maintenance after Fat loss),
 * empty for Gain first, whose phases are fixed and described in `note`.
 * `starting_calories_text` is PH-035, null in training-only mode.
 * `templates` are the first mesocycle's training choices: resensitization or
 * balanced after Fat loss (PH-036), otherwise one standard template.
 * `corridor_text` is PH-037 and `step_up` PH-044, both only for Maintenance
 * after Fat loss with nutrition on. `warnings` cover PH-034, PH-038, PH-040,
 * TBL-010 and the PH-046 fat and carb checks; `readiness` the PH-046
 * questions, empty for Maintenance. `refusal` blocks confirming (TBL-010).
 */
final class PlanTransitionOption extends CapabilityData
{
    /**
     * @param  list<PlanLengthChoice>  $lengths
     * @param  list<PlanTransitionTemplate>  $templates
     * @param  list<Status>  $warnings
     * @param  list<PlanReadinessQuestion>  $readiness
     */
    public function __construct(
        #[Field(enum: DomainValues::PHASE_TYPES)]
        public readonly string $choice,
        public readonly bool $gain_first,
        public readonly string $title,
        public readonly string $detail,
        public readonly bool $is_recommended,
        public readonly bool $is_selected,
        #[Field(items: PlanLengthChoice::class)]
        public readonly array $lengths,
        public readonly ?string $note,
        public readonly ?string $starting_calories_text,
        #[Field(items: PlanTransitionTemplate::class)]
        public readonly array $templates,
        public readonly ?string $corridor_text,
        public readonly ?PlanStepUp $step_up,
        #[Field(items: Status::class)]
        public readonly array $warnings,
        #[Field(items: PlanReadinessQuestion::class)]
        public readonly array $readiness,
        public readonly ?string $refusal,
    ) {}
}
