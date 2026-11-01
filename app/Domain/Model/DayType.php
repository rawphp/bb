<?php

namespace App\Domain\Model;

/** Day type that drives carbs and session intensity (TBL-031, TBL-032). */
enum DayType: string
{
    case Rest = 'rest';
    case Light = 'light';
    case Moderate = 'moderate';
    case Hard = 'hard';
    case Deload = 'deload';
}
