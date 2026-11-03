<?php

namespace App\Domain\Onboarding;

/** ONB-014: a busy period on some ISO weekdays, in minutes after midnight. */
final readonly class BusyPeriodChoice
{
    /** @param  list<int>  $weekdays */
    public function __construct(
        public string $id,
        public string $label,
        public int $startMinutes,
        public int $endMinutes,
        public array $weekdays,
    ) {}
}
