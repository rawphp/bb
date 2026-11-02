<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Macros\CarbBoundsWarning;
use App\Domain\Model\Day;
use App\Domain\Model\MealTarget;

/** One day of a built week. */
final readonly class WeekDay
{
    /**
     * @param  Day  $day  The start of the day, and SCH-038: rest, or the highest intensity of the day's workouts.
     * @param  list<WeekWorkout>  $workouts
     * @param  list<WeekMeal>  $meals  In time order; empty in training-only mode.
     * @param  ?MealTarget  $target  The day's totals; null in training-only mode.
     */
    public function __construct(
        public Day $day,
        public array $workouts,
        public array $meals,
        public ?MealTarget $target,
        public ?CarbBoundsWarning $carbBoundsWarning,
    ) {}
}
