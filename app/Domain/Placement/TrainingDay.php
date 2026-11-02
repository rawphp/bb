<?php

namespace App\Domain\Placement;

/** A training weekday and its workout time (ONB-011). */
final readonly class TrainingDay
{
    /**
     * @param  int  $weekday  1 = Sunday ... 7 = Saturday.
     */
    public function __construct(
        public int $weekday,
        public WorkoutTime $time,
    ) {}
}
