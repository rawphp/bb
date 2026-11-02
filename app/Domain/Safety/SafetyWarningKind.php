<?php

namespace App\Domain\Safety;

enum SafetyWarningKind: string
{
    case Glp1MuscleLoss = 'glp1MuscleLoss';
    case HighStepTarget = 'highStepTarget';
    case DayFatBelowFloor = 'dayFatBelowFloor';
    case MealFatBelowMinimum = 'mealFatBelowMinimum';
    case MealCarbsBelowMinimum = 'mealCarbsBelowMinimum';
    case MaintenanceFirst = 'maintenanceFirst';
}
