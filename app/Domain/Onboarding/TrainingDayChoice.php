<?php

namespace App\Domain\Onboarding;

/** ONB-011: a training weekday (ISO, 1 = Monday) with its start time and expected length, in minutes. */
final readonly class TrainingDayChoice
{
    public function __construct(
        public int $weekday,
        public int $startMinutes,
        public int $lengthMinutes,
    ) {}
}
