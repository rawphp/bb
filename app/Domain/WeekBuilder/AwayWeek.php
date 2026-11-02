<?php

namespace App\Domain\WeekBuilder;

use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;

/** SCH-060: the user's choices for an away week. */
final readonly class AwayWeek
{
    public function __construct(
        public Training $training,
        public Calories $calories,
    ) {}
}
