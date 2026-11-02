<?php

namespace App\Domain\Phases;

/** Why the user is not ready for a new fat loss or muscle gain (PH-046). */
enum ReadinessFailure: string
{
    case DietFatigueHigh = 'dietFatigueHigh';
    case TrainingFatigueHigh = 'trainingFatigueHigh';
    case CalendarNotClear = 'calendarNotClear';
    case FatTooLow = 'fatTooLow';
    case CarbsTooLow = 'carbsTooLow';
}
