<?php

namespace App\Domain\Phases;

/** PH-041: the choices the final weekly review offers. */
enum FinalReviewChoice: string
{
    case KeepGoal = 'keepGoal';
    case ChooseNewGoal = 'chooseNewGoal';
    case EndPlan = 'endPlan';
}
