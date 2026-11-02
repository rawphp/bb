<?php

namespace App\Domain\Shopping;

use DateTimeImmutable;

/** An inclusive range of dates, lower to upper. */
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $lower,
        public DateTimeImmutable $upper,
    ) {}
}
