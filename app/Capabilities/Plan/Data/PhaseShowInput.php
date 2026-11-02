<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Which phase to show (PLAN-004).
 */
final class PhaseShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $phase_id,
    ) {}
}
