<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A future plan week the Away sheet can mark (SCH-060). */
final class AwayWeekOption extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $week_start,
        #[Field(description: '"Week 5 · Nov 9"')] public readonly string $label,
        public readonly bool $is_away,
        public readonly bool $selected,
    ) {}
}
