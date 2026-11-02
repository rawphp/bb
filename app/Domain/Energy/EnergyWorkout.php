<?php

namespace App\Domain\Energy;

use App\Domain\Model\DayType;

/** One workout on a day: a mesocycle session or an "Other activity" (SCH-032). */
final readonly class EnergyWorkout
{
    /**
     * @param  DayType  $intensity  light, moderate or hard; never deload or rest
     */
    public function __construct(
        public float $minutes,
        public DayType $intensity,
    ) {}
}
