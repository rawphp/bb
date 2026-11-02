<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A training day and its session. */
final class TrainingDay extends CapabilityData
{
    public function __construct(
        #[Field(description: 'ISO weekday: 1 is Monday, 7 is Sunday', minimum: 1, maximum: 7)]
        public readonly int $weekday,
        public readonly string $label,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $start,
        public readonly int $minutes,
    ) {}
}
