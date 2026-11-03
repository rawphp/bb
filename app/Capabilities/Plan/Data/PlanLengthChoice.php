<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One length a phase may have, with what it would do. Used by Add a phase,
 * Adjust phase length (PH-006) and the transition screen (PH-031). Only
 * lengths TBL-010 allows are listed; `weeks` null is no end (PH-005).
 *
 * `warnings` never block (PH-034, PH-038, PH-042, TBL-010). `mesocycles` is
 * the proposal that fills the phase (PLAN-020), listed only in Add a phase.
 * `fixes` is set in Adjust phase length when the new end falls mid-mesocycle
 * (PLAN-021): the user picks one and `phase_length_set` sends it.
 */
final class PlanLengthChoice extends CapabilityData
{
    /**
     * @param  list<Status>  $warnings
     * @param  list<PlanProposedMesocycle>  $mesocycles
     * @param  list<PlanLengthFix>  $fixes
     */
    public function __construct(
        public readonly ?int $weeks,
        public readonly string $label,
        public readonly string $ends_text,
        public readonly bool $is_selected,
        #[Field(items: Status::class)]
        public readonly array $warnings,
        #[Field(items: PlanProposedMesocycle::class)]
        public readonly array $mesocycles,
        #[Field(items: PlanLengthFix::class)]
        public readonly array $fixes,
    ) {}
}
