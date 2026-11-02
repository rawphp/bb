<?php

namespace App\Domain\Progress;

/** Calories and protein of a day: a target, or what was consumed. */
final readonly class DayTotals
{
    public function __construct(
        public float $kcal,
        public float $proteinG,
    ) {}
}
