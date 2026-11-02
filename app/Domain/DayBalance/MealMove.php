<?php

namespace App\Domain\DayBalance;

/** The two meals after a calorie move between them (DAY-020). */
final readonly class MealMove
{
    public function __construct(
        public Slot $from,
        public Slot $to,
    ) {}
}
