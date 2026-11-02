<?php

namespace App\Domain\Macros;

/** What to tell the user when a target is refused. */
enum TargetRecommendation: string
{
    case RaiseToMaintenance = 'raiseToMaintenance';
    case RaiseCaloriesOrEndPhase = 'raiseCaloriesOrEndPhase';
}
