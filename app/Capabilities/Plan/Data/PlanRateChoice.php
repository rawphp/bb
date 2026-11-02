<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Train\Data\Measure;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A TBL-010 rate preset ("Highest success", "Slow & steady"). `rate` is
 * signed percent of bodyweight per week; its text reads "Lose 0.75% a week".
 */
final class PlanRateChoice extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly Measure $rate,
        public readonly bool $is_selected,
    ) {}
}
