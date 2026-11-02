<?php

namespace App\Domain\Placement;

/** When a workout starts and how long it runs. */
final readonly class WorkoutTime
{
    public function __construct(
        public ClockTime $start,
        public int $lengthMinutes,
    ) {}
}
