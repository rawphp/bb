<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * End the current phase early (PH-007): it ends at the next plan-week
 * boundary and `plan_show` then carries the transition (PH-030).
 */
final class PhaseEndInput extends CapabilityData
{
    public function __construct(
        public readonly string $phase_id,
    ) {}
}
