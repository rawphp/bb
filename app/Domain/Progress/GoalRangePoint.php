<?php

namespace App\Domain\Progress;

use DateTimeImmutable;

/** The goal line and its range on one day, in kg. */
final readonly class GoalRangePoint
{
    public function __construct(
        public DateTimeImmutable $day,
        public float $goalKg,
        public float $lowerKg,
        public float $upperKg,
    ) {}
}
