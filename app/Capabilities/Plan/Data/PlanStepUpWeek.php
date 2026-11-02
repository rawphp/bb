<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One Maintenance week's daily calorie target while stepping up (PH-044).
 */
final class PlanStepUpWeek extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly string $kcal_text,
    ) {}
}
