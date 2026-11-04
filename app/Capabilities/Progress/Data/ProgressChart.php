<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Daily weights and trend weight against the goal range, in the user's unit
 * (ANA-001). Waist has its own line once 4 readings exist (WT-021). Each
 * weigh-in day has a readout the chart shows when the user picks that day (ANA-012).
 */
final class ProgressChart extends CapabilityData
{
    /**
     * @param  list<ChartPoint>  $weights
     * @param  list<ChartPoint>  $trend
     * @param  list<GoalPoint>  $goal
     * @param  list<ChartPoint>  $waist  empty until 4 waist readings exist
     * @param  list<ChartReadout>  $readouts  one per day in $weights, in the same order
     */
    public function __construct(
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $unit,
        #[Field(items: ChartPoint::class)]
        public readonly array $weights,
        #[Field(items: ChartPoint::class)]
        public readonly array $trend,
        #[Field(items: GoalPoint::class)]
        public readonly array $goal,
        #[Field(enum: ['cm', 'in'])]
        public readonly string $waist_unit,
        #[Field(items: ChartPoint::class)]
        public readonly array $waist,
        public readonly string $accessibility_label,
        #[Field(description: 'ANA-012: one per day in weights, in the same order.', items: ChartReadout::class)]
        public readonly array $readouts,
    ) {}
}
