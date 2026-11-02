<?php

namespace App\Domain\Review;

use DateTimeImmutable;

/** A `plan_weeks` row as the review's timing rules read it: its flags and when its review was saved. */
final readonly class StoredPlanWeek
{
    /** @param  ?DateTimeImmutable  $reviewedAt  REV-001: set when the review of this week is saved */
    public function __construct(
        public DateTimeImmutable $start,
        public bool $isDeload = false,
        public bool $isDietBreak = false,
        public bool $isAway = false,
        public ?DateTimeImmutable $reviewedAt = null,
    ) {}
}
