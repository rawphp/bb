<?php

namespace App\Domain\Retarget;

/** Why a re-target was refused. */
enum RetargetRefusal: string
{
    /** The day has no mesocycle session to change. */
    case NoSession = 'no_session';

    /** SCH-038: the day already holds a mesocycle session; ask for a different day. */
    case DayHasSession = 'day_has_session';

    /** SCH-057: a session never moves out of its plan week. */
    case OutsidePlanWeek = 'outside_plan_week';

    /** SCH-041: the window cannot hold the day's meals around the workout. */
    case MealsDoNotFit = 'meals_do_not_fit';
}
