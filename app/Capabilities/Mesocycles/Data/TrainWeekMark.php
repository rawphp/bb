<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One week on the current mesocycle card's track (MESO-008): done, this week, still to come, or cut off by an early
 * end. The last week is the deload.
 */
final class TrainWeekMark extends CapabilityData
{
    public function __construct(
        public readonly int $number,
        #[Field(enum: ['past', 'current', 'upcoming', 'skipped'])]
        public readonly string $state,
        public readonly bool $deload,
    ) {}
}
