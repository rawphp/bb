<?php

namespace App\Domain\Phases;

/** PH-035: measured expenditure (REV-021) or the setup formula (NUT-002). */
enum StartingCaloriesSource: string
{
    case Measured = 'measured';
    case Formula = 'formula';
}
