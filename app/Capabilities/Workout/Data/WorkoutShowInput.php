<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One session of a mesocycle by its week and day (WO-001). The same read
 * opens an earlier session for look back (WO-008), read only.
 */
final class WorkoutShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(minimum: 1)]
        public readonly int $week,
        #[Field(minimum: 1)]
        public readonly int $day,
    ) {}
}
