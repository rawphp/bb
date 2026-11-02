<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A mesocycle or the active rest of a prebuilt plan, in time order
 * (PLAN-030). `start_week` counts from the prebuilt plan's first week, 0.
 */
final class PlanPrebuiltSegment extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['mesocycle', 'active_rest'])]
        public readonly string $kind,
        public readonly string $label,
        public readonly int $start_week,
        public readonly int $weeks,
    ) {}
}
