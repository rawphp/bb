<?php

namespace App\Domain\Placement;

final readonly class DayPlacement
{
    /**
     * @param  list<PlacedMeal>  $meals  In eating-window order, which is time order from the window's start.
     * @param  ?ClockTime  $shake  SCH-043: the intra-workout shake, offered for sessions over 60 minutes, at the
     *                             workout's start so it moves with the workout.
     */
    public function __construct(
        public array $meals,
        public ?ClockTime $shake,
    ) {}
}
