<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** One numbered line of the WO-024 warm-up card. */
final class WorkoutWarmUpStep extends CapabilityData
{
    public function __construct(
        public readonly string $text,
    ) {}
}
