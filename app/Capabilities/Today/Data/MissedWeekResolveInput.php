<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Answer the missed-weeks prompt: restart the week, or end the mesocycle (SCH-058, MESO-043, MESO-044). */
final class MissedWeekResolveInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(enum: ['restart', 'end'])] public readonly string $choice,
        #[Field(description: 'End only: start a diet break next week.')] public readonly bool $diet_break = false,
    ) {}
}
