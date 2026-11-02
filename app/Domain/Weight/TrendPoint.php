<?php

namespace App\Domain\Weight;

use DateTimeImmutable;

/** One calendar day from the first weigh-in to the last. */
final readonly class TrendPoint
{
    public function __construct(
        /** Start of the day. */
        public DateTimeImmutable $day,
        /** That day's earliest reading, or the last day's weight when there was none (WT-010). */
        public float $weightKg,
        public float $trendKg,
        public bool $isWeighIn,
    ) {}
}
