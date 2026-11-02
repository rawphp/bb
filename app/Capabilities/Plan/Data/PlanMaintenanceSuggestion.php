<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * PH-045: no Maintenance in the last 5 months, so the Plan screen suggests a
 * short low-volume one. Accepting opens Add a phase on Maintenance at `weeks`.
 */
final class PlanMaintenanceSuggestion extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        public readonly int $weeks,
    ) {}
}
