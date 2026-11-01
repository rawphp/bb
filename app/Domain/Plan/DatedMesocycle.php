<?php

namespace App\Domain\Plan;

use DateTimeImmutable;

/** A stored mesocycle's first day and length in weeks, as the plan places it (PLAN-002, SCH-060). */
final readonly class DatedMesocycle
{
    public function __construct(
        public DateTimeImmutable $start,
        public int $weeks,
    ) {}
}
