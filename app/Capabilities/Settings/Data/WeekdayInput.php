<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A weekday. */
final class WeekdayInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'ISO weekday: 1 is Monday, 7 is Sunday', minimum: 1, maximum: 7)]
        public readonly int $weekday,
    ) {}
}
