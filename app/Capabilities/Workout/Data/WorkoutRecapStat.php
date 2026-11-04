<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** WO-011: one number of the recap, "48 min" under "Time". */
final class WorkoutRecapStat extends CapabilityData
{
    public function __construct(
        /** "Time", "Sets" or "Lifted". */
        public readonly string $label,
        /** "48 min", "8", "4,172 kg". */
        public readonly string $value,
    ) {}
}
