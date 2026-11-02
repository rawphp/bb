<?php

namespace App\Domain\Review;

/** REV-027: what to do about a stall. */
enum StallChange: string
{
    case CutCalories = 'cutCalories';
    case AddSteps = 'addSteps';
    case Hold = 'hold';
}
