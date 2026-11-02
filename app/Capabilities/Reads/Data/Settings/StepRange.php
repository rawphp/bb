<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One weekday of the step plan. Both ends are null on a day with no range. */
final class StepRange extends CapabilityData
{
    public function __construct(
        #[Field(description: 'ISO weekday: 1 is Monday, 7 is Sunday', minimum: 1, maximum: 7)]
        public readonly int $weekday,
        public readonly string $label,
        public readonly ?int $min,
        public readonly ?int $max,
    ) {}
}
