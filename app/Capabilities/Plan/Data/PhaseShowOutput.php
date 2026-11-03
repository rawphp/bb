<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A phase opened from the timeline (PLAN-004, PhaseDetailView): type, dates,
 * progress, rate, start, target and current trend weight, and the event date
 * (PH-043). Weights are null until the phase has started and has weigh-ins;
 * the rate and target are null in training-only mode (PLAN-007).
 * `progress` is the bar's filled share, 0 to 1, null with no end (PH-005).
 *
 * `event_date` (ISO) prefills the date picker; `warnings` hold PH-042 and
 * `event_offer` the PLAN-028 offer to move the end to the event week.
 * `can_end` offers End phase (PH-007). `lengths` fill AdjustLengthSheet
 * (PH-006, PLAN-021); `goals` fill ChangeGoalSheet (PH-009 to PH-012). Both
 * are empty when the phase can no longer change (it is over).
 */
final class PhaseShowOutput extends CapabilityData
{
    /**
     * @param  list<Status>  $warnings
     * @param  list<PlanLengthChoice>  $lengths
     * @param  list<PhaseGoalChoice>  $goals
     */
    public function __construct(
        public readonly string $id,
        #[Field(enum: DomainValues::PHASE_TYPES)]
        public readonly string $type,
        public readonly string $title,
        public readonly string $dates_text,
        public readonly ?float $progress,
        public readonly string $progress_text,
        public readonly string $length_text,
        public readonly ?string $rate_text,
        public readonly ?string $start_weight_text,
        public readonly ?string $target_weight_text,
        public readonly ?string $current_weight_text,
        public readonly ?string $change_text,
        public readonly ?string $event_date,
        public readonly ?string $event_text,
        #[Field(items: Status::class)]
        public readonly array $warnings,
        public readonly ?PhaseEventOffer $event_offer,
        public readonly bool $can_end,
        #[Field(items: PlanLengthChoice::class)]
        public readonly array $lengths,
        #[Field(items: PhaseGoalChoice::class)]
        public readonly array $goals,
    ) {}
}
