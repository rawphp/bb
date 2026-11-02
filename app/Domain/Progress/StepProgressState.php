<?php

namespace App\Domain\Progress;

enum StepProgressState: string
{
    case BelowRange = 'belowRange';
    case WithinRange = 'withinRange';
    case AboveRange = 'aboveRange';
}
