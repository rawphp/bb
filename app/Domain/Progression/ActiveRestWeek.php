<?php

namespace App\Domain\Progression;

/** MESO-064: one week of an active rest. */
enum ActiveRestWeek: string
{
    case DeloadWeek = 'deloadWeek';
    case WeekOff = 'weekOff';
}
