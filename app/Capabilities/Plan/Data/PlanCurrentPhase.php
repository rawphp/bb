<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The phase running today (CurrentPhaseView), for the Plan screen card and
 * the More menu's Adjust phase length and End current phase (PH-006, PH-007).
 * `progress` is the bar's filled share, 0 to 1; null with no end (PH-005).
 */
final class PlanCurrentPhase extends CapabilityData
{
    public function __construct(
        public readonly string $phase_id,
        public readonly string $title,
        public readonly string $dates_text,
        public readonly string $progress_text,
        public readonly ?float $progress,
    ) {}
}
