<?php

namespace App\Domain\Model;

/** A daily step range (the settings step plan): ISO weekday and the target range. */
final readonly class StepPlanDay
{
    public function __construct(
        public int $weekday,
        public int $min,
        public int $max,
    ) {}
}
