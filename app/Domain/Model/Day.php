<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/** A calendar day and its type. */
final readonly class Day
{
    public function __construct(
        public DateTimeImmutable $date,
        public DayType $type,
    ) {}
}
