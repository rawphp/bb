<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The phase's goal range on one day: low, goal line and high, in the chart's unit (ANA-001). */
final class GoalPoint extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $date,
        public readonly float $low,
        public readonly float $goal,
        public readonly float $high,
    ) {}
}
