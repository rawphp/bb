<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * WO-008: an earlier session of this mesocycle. The view opens it with
 * workout_show {mesocycle_id, week, day}, which comes back read only.
 */
final class WorkoutLookBackRow extends CapabilityData
{
    public function __construct(
        public readonly int $week,
        public readonly int $day,
        /** "Week 2 · Day 1 · Upper A". */
        public readonly string $title,
        /** "Done", "Skipped" or "Started". */
        public readonly Status $status,
    ) {}
}
