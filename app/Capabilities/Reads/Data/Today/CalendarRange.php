<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The dates the month view can jump to (SCH-023). */
final class CalendarRange extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $first,
        #[Field(format: 'date')] public readonly string $last,
    ) {}
}
