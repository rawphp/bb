<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One item of the day in time order (SCH-001); its details are in the list named by `kind`, under the same id. */
final class TimelineEntry extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['weigh_in', 'meal', 'workout', 'other_activity', 'busy'])] public readonly string $kind,
        public readonly string $id,
    ) {}
}
