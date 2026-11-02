<?php

namespace App\Support\Plan;

use DateTimeImmutable;

/** One plan week as the timeline marks it (PLAN-008, PLAN-022 to PLAN-025). */
final readonly class TimelineWeek
{
    public function __construct(
        public int $index,
        public DateTimeImmutable $start,
        /** 7, or fewer or more in the first week and a review-day transition week (PLAN-008). */
        public int $dayCount,
        public bool $isPast,
        public bool $isDeload,
        /** PLAN-022, PLAN-025: a week at maintenance calories in a cut, planned or not. */
        public bool $isDietBreak,
        /** "Deload · maintenance calories"; null for a week that is not a deload. */
        public ?string $deloadLabel,
        /** PLAN-023: a Fat loss or Mini-cut deload whose diet break the user may turn off. */
        public bool $canTurnOffDietBreak,
        /** PLAN-023: the user turned it off; the week deloads in a deficit. */
        public bool $dietBreakIsOff,
    ) {}
}
