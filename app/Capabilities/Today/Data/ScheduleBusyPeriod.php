<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A busy period of Edit schedule. ScheduleUpdateInput holds its rules: the pipeline runs only the top-level input's. */
final class ScheduleBusyPeriod extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $start,
        public readonly string $end,
    ) {}
}
