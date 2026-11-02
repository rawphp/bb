<?php

namespace App\Domain\Review;

/** The four ways the calorie step can end (REV-010). */
enum CalorieOptionKind
{
    case Recommendation;
    case RepeatThisWeek;
    case ChooseOwn;
    case DropFatigue;
}
