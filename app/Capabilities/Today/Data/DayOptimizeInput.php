<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Re-place the day's unchecked meals as previewed (SCH-036). */
final class DayOptimizeInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
    ) {}
}
