<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/** A review-day change (PLAN-008): from `from` (the first day of the transition week) every week starts on `weekday`. */
final readonly class ReviewDayChange
{
    /**
     * @param  int  $weekday  1 = Sunday ... 7 = Saturday
     */
    public function __construct(
        public DateTimeImmutable $from,
        public int $weekday,
    ) {}
}
