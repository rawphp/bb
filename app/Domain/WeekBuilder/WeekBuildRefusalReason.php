<?php

namespace App\Domain\WeekBuilder;

/** Why a week could not be built. */
enum WeekBuildRefusalReason: string
{
    /** PLAN-002: no phase covers the week. */
    case NoPhase = 'no_phase';

    /** SAFE-011, SAFE-012: the targets refuse the cut. */
    case Targets = 'targets';

    /** SCH-041: the window cannot hold a day's meals. */
    case MealsDoNotFit = 'meals_do_not_fit';

    /** NUT-022, NUT-024: a calorie move the week does not allow. */
    case Move = 'move';
}
