<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A mesocycle on the timeline's second row (PLAN-003), placed on the plan-week
 * grid with any paused weeks inside it (PLAN-002). Tapping it opens the
 * mesocycle (PLAN-005, MESO-040).
 */
final class PlanMesocycleBand extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $start_week,
        public readonly int $weeks,
        public readonly bool $is_current,
    ) {}
}
