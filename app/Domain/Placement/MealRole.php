<?php

namespace App\Domain\Placement;

enum MealRole: string
{
    case Regular = 'regular';
    case PreWorkout = 'pre_workout';
    case PostWorkout = 'post_workout';
}
