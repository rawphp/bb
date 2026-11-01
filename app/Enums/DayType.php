<?php

namespace App\Enums;

/** SCH-006: the day types a day target is computed for. */
enum DayType: string
{
    case Rest = 'rest';
    case Light = 'light';
    case Moderate = 'moderate';
    case Hard = 'hard';
    case Deload = 'deload';
}
