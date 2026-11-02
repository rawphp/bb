<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A busy period. It needs at least one weekday. */
final class BusyPeriodInput extends CapabilityData
{
    /**
     * @param  list<WeekdayInput>  $weekdays
     */
    public function __construct(
        #[Field(minLength: 1, maxLength: 50)]
        public readonly string $label,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $start,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $end,
        #[Field(minItems: 1, items: WeekdayInput::class)]
        public readonly array $weekdays,
    ) {}
}
