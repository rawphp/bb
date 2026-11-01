<?php

namespace App\Domain\Macros;

/** Why a cut was refused (SAFE-011, SAFE-012). */
enum TargetRefusalReason: string
{
    /** SAFE-011: BMI is at or below 17. */
    case BmiAtOrBelowFloor = 'bmiAtOrBelowFloor';
    /** SAFE-012: the cut would break the carb minimum (or fat floor) on this day. */
    case BreaksFloor = 'breaksFloor';
}
