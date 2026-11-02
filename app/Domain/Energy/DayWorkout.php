<?php

namespace App\Domain\Energy;

use App\Domain\Model\DayType;

/** SCH-038: a day's summed workout energy and its day type. */
final readonly class DayWorkout
{
    public function __construct(
        public float $kcal,
        public DayType $dayType,
    ) {}
}
