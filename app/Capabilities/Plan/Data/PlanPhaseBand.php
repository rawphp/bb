<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A phase on the timeline's top row (PLAN-003). Tapping it opens `phase_show`
 * (PLAN-004). `weeks` is the band's width: a phase with no end (PH-005) runs
 * to the end of the timeline. `outline` is a phase past the next one, kept as
 * an outline until it starts (PLAN-006). `rate_text` is null in
 * training-only mode (PLAN-007).
 */
final class PlanPhaseBand extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        #[Field(enum: ['fat_loss', 'muscle_gain', 'maintenance', 'mini_cut'])]
        public readonly string $type,
        public readonly string $title,
        public readonly int $start_week,
        public readonly int $weeks,
        public readonly string $dates_text,
        public readonly ?string $rate_text,
        public readonly ?string $event_text,
        #[Field(enum: ['past', 'current', 'future', 'outline'])]
        public readonly string $state,
    ) {}
}
