<?php

namespace App\Domain\Onboarding;

/** ONB-013: a daily step range for one ISO weekday. */
final readonly class StepRangeChoice
{
    public function __construct(
        public int $weekday,
        public int $min,
        public int $max,
    ) {}
}
