<?php

namespace App\Domain\Safety;

/** One week's average daily calories, consumed (checked-in intake, REV-022) and targeted. */
final readonly class IntakeWeek
{
    public function __construct(
        public float $averageConsumedKcal,
        public float $averageTargetKcal,
    ) {}
}
