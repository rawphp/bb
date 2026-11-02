<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A weekday chip of a busy period: on or off. */
final class WeekdayChip extends CapabilityData
{
    public function __construct(
        #[Field(description: 'ISO weekday: 1 is Monday, 7 is Sunday', minimum: 1, maximum: 7)]
        public readonly int $weekday,
        public readonly string $label,
        public readonly bool $on,
    ) {}
}
