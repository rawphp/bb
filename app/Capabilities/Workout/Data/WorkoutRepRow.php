<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Train\Data\Measure;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One row of a set's rep table (CAP-016, PROG-024): a weight the exercise's
 * load steps allow within 15% of the planned weight, and the target reps at
 * that weight for an equal effort.
 */
final class WorkoutRepRow extends CapabilityData
{
    public function __construct(
        public readonly Measure $weight,
        public readonly int $reps,
    ) {}
}
