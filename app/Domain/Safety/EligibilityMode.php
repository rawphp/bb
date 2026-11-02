<?php

namespace App\Domain\Safety;

enum EligibilityMode: string
{
    case AllTargets = 'allTargets';
    /** "Trying to conceive": Maintenance and Muscle gain only. */
    case FatLossBlocked = 'fatLossBlocked';
    /** SAFE-003: training features with nutrition targets turned off. */
    case TrainingOnly = 'trainingOnly';
}
