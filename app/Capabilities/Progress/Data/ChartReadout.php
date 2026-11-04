<?php

namespace App\Capabilities\Progress\Data;

use App\Capabilities\Support\Line;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** ANA-012: what the chart reads out for one weigh-in day: "Mon 2 Nov", then the weigh-in, trend, goal range and waist. */
final class ChartReadout extends CapabilityData
{
    /** @param  list<Line>  $lines */
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $date,
        public readonly string $title,
        #[Field(items: Line::class)]
        public readonly array $lines,
    ) {}
}
