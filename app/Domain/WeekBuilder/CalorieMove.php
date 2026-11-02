<?php

namespace App\Domain\WeekBuilder;

/** NUT-021: move this many calories from one day of the week (0 is its first) to another. */
final readonly class CalorieMove
{
    public function __construct(
        public int $from,
        public int $to,
        public float $kcal,
    ) {}
}
