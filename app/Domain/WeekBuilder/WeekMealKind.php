<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Placement\MealRole;

enum WeekMealKind: string
{
    case Regular = 'regular';
    case PreWorkout = 'pre_workout';
    case PostWorkout = 'post_workout';

    /** SCH-043. */
    case IntraWorkoutShake = 'intra_workout_shake';

    /** The kind of a meal the placement gave a role. */
    public static function of(MealRole $role): self
    {
        return match ($role) {
            MealRole::Regular => self::Regular,
            MealRole::PreWorkout => self::PreWorkout,
            MealRole::PostWorkout => self::PostWorkout,
        };
    }
}
