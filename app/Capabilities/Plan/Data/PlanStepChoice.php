<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Train\Data\Measure;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One PH-044 step size (250 to 500 kcal a day) and the week-by-week targets
 * it gives over the longest allowed Maintenance. The screen lists the first
 * rows, one per week of the length chosen.
 */
final class PlanStepChoice extends CapabilityData
{
    /**
     * @param  list<PlanStepUpWeek>  $schedule
     */
    public function __construct(
        public readonly Measure $step,
        public readonly bool $is_selected,
        #[Field(items: PlanStepUpWeek::class)]
        public readonly array $schedule,
    ) {}
}
