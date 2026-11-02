<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A training weekday chip (SCH-039). */
final class WeekdayChip extends CapabilityData
{
    public function __construct(
        #[Field(description: '1 is Monday.')] public readonly int $iso,
        #[Field(description: '"Mon"')] public readonly string $label,
        public readonly bool $selected,
    ) {}
}
