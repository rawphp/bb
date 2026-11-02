<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Another day of the plan week (NUT-021). */
final class AdjustDayOption extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        public readonly string $label,
        #[Field(description: 'false once the day is finished (NUT-024).')] public readonly bool $enabled,
    ) {}
}
