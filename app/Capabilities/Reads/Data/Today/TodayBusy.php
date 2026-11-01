<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A greyed busy period (SCH-005). */
final class TodayBusy extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        #[Field(description: '"9:00 AM – 5:00 PM"')] public readonly string $time_text,
    ) {}
}
