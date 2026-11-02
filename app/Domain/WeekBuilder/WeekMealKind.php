<?php

namespace App\Domain\WeekBuilder;

enum WeekMealKind: string
{
    case Regular = 'regular';
    case PreWorkout = 'pre_workout';
    case PostWorkout = 'post_workout';

    /** SCH-043. */
    case IntraWorkoutShake = 'intra_workout_shake';
}
