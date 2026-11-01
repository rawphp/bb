<?php

namespace App\Domain\MealFit;

/** Calories and the three macros. */
enum Macro: string
{
    case Calories = 'calories';
    case Protein = 'protein';
    case Fat = 'fat';
    case Carbs = 'carbs';
}
