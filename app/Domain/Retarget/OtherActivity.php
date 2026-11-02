<?php

namespace App\Domain\Retarget;

use App\Domain\Model\DayType;

/** SCH-032 "Other activity": sport or cardio on a day, logged for nutrition only. */
final readonly class OtherActivity
{
    /**
     * @param  DayType  $intensity  NUT-005: the intensity the user picked, light, moderate or hard.
     */
    public function __construct(
        public string $name,
        public int $minutes,
        public DayType $intensity,
    ) {}
}
