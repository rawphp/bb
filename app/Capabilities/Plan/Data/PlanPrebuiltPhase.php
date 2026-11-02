<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A phase a prebuilt plan adds (PLAN-030). `rate_text` is null in
 * training-only mode (PLAN-007).
 */
final class PlanPrebuiltPhase extends CapabilityData
{
    public function __construct(
        #[Field(enum: DomainValues::PHASE_TYPES)]
        public readonly string $type,
        public readonly string $title,
        public readonly string $weeks_text,
        public readonly string $dates_text,
        public readonly ?string $rate_text,
    ) {}
}
