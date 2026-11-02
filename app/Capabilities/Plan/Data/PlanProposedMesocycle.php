<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One mesocycle proposed to fill a new phase (PLAN-020, PLAN-026), its last
 * week the deload.
 */
final class PlanProposedMesocycle extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly string $weeks_text,
        public readonly string $dates_text,
    ) {}
}
