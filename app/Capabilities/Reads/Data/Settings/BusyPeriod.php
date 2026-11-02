<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A busy period: no meal is placed in it (SCH-033). */
final class BusyPeriod extends CapabilityData
{
    /**
     * @param  list<WeekdayChip>  $days
     */
    public function __construct(
        public readonly string $label,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $start,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $end,
        #[Field(description: 'all seven weekdays, Monday first', items: WeekdayChip::class)]
        public readonly array $days,
    ) {}
}
