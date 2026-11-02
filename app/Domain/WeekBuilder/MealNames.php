<?php

namespace App\Domain\WeekBuilder;

/** Names the week builder gives meals, for the rules that need to tell one by its name. */
final class MealNames
{
    /** SCH-043: the shake taken during a workout. MEAL-060 keeps its time when meal times are copied. */
    public const INTRA_WORKOUT_SHAKE = 'Intra-workout shake';

    /** SCH-031: the meal Add meal puts on a day. */
    public const EXTRA_MEAL = 'Extra meal';
}
