<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One day's value on a chart, in the chart's unit. */
final class ChartPoint extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $date,
        public readonly float $value,
    ) {}
}
