<?php

namespace App\Domain\Phases;

/** PH-044: step calories up after fat loss, or go straight to maintenance. */
enum CalorieStepUpMode: string
{
    case StepUp = 'stepUp';
    case StraightToMaintenance = 'straightToMaintenance';
}
