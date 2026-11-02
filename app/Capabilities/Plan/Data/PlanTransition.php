<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The phase transition screen (PH-030): how the ending phase went and what
 * comes next. Present on `plan_show` while a transition is due: the phase
 * reached its end date, or `phase_end` ended it (PH-007). The nutrition rows
 * are null in training-only mode. `phase_transition_complete` sends the
 * choice.
 */
final class PlanTransition extends CapabilityData
{
    /**
     * @param  list<PlanTransitionOption>  $options
     */
    public function __construct(
        public readonly string $phase_id,
        public readonly string $title,
        public readonly string $length_text,
        public readonly ?string $weight_change_text,
        public readonly ?string $rate_text,
        public readonly ?string $adherence_text,
        public readonly string $mesocycles_text,
        public readonly string $next_from_text,
        #[Field(items: PlanTransitionOption::class)]
        public readonly array $options,
    ) {}
}
