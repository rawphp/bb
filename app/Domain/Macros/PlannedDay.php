<?php

namespace App\Domain\Macros;

use App\Domain\Model\DayType;

/** One day of the week as the macro code sees it: the day's expenditure (NUT-002, NUT-003) as a plain number. */
final readonly class PlannedDay
{
    public function __construct(
        public float $expenditureKcal,
        public DayType $type,
        public bool $isCompleted = false,
    ) {}
}
