<?php

namespace App\Domain\DayBalance;

/** Why a calorie move between meals was refused (DAY-020, SAFE-010). */
enum MealMoveRefusalReason: string
{
    /** No amount, or the same meal twice. */
    case Invalid = 'invalid';
    /** A change in a day's carbs with no meal to carry it. */
    case NoMeals = 'noMeals';
    /** The meal would keep less than 5 g of carbs. */
    case BelowCarbMinimum = 'belowCarbMinimum';
}
