<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Turn a Fat loss or Mini-cut deload week's diet break off, after the
 * deficit-deload warning, or back on (PLAN-023). `week_start` is the
 * deload's `start_date` from `plan_show`.
 */
final class PlanDietBreakSetInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $week_start,
        public readonly bool $diet_break,
    ) {}
}
