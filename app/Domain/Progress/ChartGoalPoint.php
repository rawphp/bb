<?php

namespace App\Domain\Progress;

use DateTimeImmutable;

/** One day of one phase's goal line and range. */
final readonly class ChartGoalPoint
{
    public function __construct(
        public string $phaseId,
        public DateTimeImmutable $day,
        public float $goalKg,
        public float $lowerKg,
        public float $upperKg,
    ) {}
}
