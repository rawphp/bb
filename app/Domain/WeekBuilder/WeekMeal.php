<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Model\Meal;
use App\Domain\Placement\ClockTime;

/** One meal of a built day. */
final readonly class WeekMeal
{
    /**
     * @param  Meal  $meal  Named "Meal 1" ... in time order; the shake is "Intra-workout shake".
     */
    public function __construct(
        public Meal $meal,
        public ClockTime $time,
        public WeekMealKind $kind,
    ) {}

    public function id(): string
    {
        return $this->meal->id;
    }
}
