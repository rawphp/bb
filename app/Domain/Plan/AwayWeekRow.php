<?php

namespace App\Domain\Plan;

use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;

/** What a plan week keeps of its away choices (SCH-060, PLAN-025); the default is a week with nothing flagged. */
final readonly class AwayWeekRow
{
    public function __construct(
        public bool $isAway = false,
        public ?Training $training = null,
        public ?Calories $calories = null,
        public bool $isDietBreak = false,
        public bool $keepPhaseEnd = false,
    ) {}
}
