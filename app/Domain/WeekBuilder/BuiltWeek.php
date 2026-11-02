<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Model\Phase;
use App\Domain\Model\PlanWeek;

/** A plan week built end to end. */
final readonly class BuiltWeek
{
    /**
     * @param  PlanWeek  $week  From the plan grid, with isDeload, isDietBreak and isAway set.
     * @param  Phase  $phase  The phase covering the week.
     * @param  list<WeekDay>  $days
     */
    public function __construct(
        public PlanWeek $week,
        public Phase $phase,
        public array $days,
    ) {}
}
