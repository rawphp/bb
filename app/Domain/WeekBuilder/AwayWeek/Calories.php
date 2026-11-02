<?php

namespace App\Domain\WeekBuilder\AwayWeek;

/** SCH-060: what an away week eats. */
enum Calories: string
{
    /** Eat as on a rest day, with the phase's change. */
    case RestDays = 'rest_days';
    case Maintenance = 'maintenance';
}
