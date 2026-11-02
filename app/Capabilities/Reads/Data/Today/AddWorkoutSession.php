<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Support\CapabilityData;

/** The session Add workout would move to this day (SCH-032). */
final class AddWorkoutSession extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
        public readonly string $title,
        public readonly string $time_text,
    ) {}
}
