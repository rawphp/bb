<?php

namespace App\Domain\Shopping;

use DateTimeImmutable;

/** The foods planned on one day. */
final readonly class PlannedFoodDay
{
    /** @param  list<PlannedItem>  $items */
    public function __construct(
        public DateTimeImmutable $date,
        public array $items,
    ) {}
}
