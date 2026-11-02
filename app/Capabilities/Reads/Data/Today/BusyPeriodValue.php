<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A busy period as Edit schedule shows it. */
final class BusyPeriodValue extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $start,
        public readonly string $end,
    ) {}
}
