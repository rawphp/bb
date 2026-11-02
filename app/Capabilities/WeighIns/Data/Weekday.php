<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day the schedule sheet offers, ISO numbered (1 is Monday). */
final class Weekday extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 1, maximum: 7)]
        public readonly int $number,
        public readonly string $short,
        public readonly string $full,
    ) {}
}
