<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A weekday's step range. A day left out has none. */
final class StepRangeInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'ISO weekday: 1 is Monday, 7 is Sunday', minimum: 1, maximum: 7)]
        public readonly int $weekday,
        #[Field(minimum: 0)]
        public readonly int $min,
        #[Field(minimum: 0)]
        public readonly int $max,
    ) {}
}
