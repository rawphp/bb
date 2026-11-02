<?php

namespace App\Domain\MealSplit;

enum DayEntryKind
{
    case Meal;
    case Workout;

    /** SCH-043: an intra-workout shake, tied to the workout. */
    case IntraWorkoutShake;
}
