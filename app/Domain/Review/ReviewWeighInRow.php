<?php

namespace App\Domain\Review;

use DateTimeImmutable;

/** One line of the weigh-in list. */
final readonly class ReviewWeighInRow
{
    /**
     * @param  DateTimeImmutable  $day  the start of the day
     * @param  ?float  $weightKg  the day's earliest reading, the one the trend uses; null when there is none
     * @param  bool  $isMissing  a scheduled weigh-in with no reading, shown in orange with an option to add it (REV-003)
     */
    public function __construct(
        public DateTimeImmutable $day,
        public ?float $weightKg,
        public bool $isScheduled,
        public bool $isMissing,
    ) {}
}
