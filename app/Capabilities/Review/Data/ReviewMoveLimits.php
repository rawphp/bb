<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** The stepper of the move-calories sheet (REV-004, NUT-021). */
final class ReviewMoveLimits extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly int $min_kcal,
        public readonly int $max_kcal,
        public readonly int $step_kcal,
    ) {}
}
