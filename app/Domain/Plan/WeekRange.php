<?php

namespace App\Domain\Plan;

/** Plan weeks from lower up to, not including, upper. An open-ended phase runs to PHP_INT_MAX (PLAN-002). */
final readonly class WeekRange
{
    public function __construct(
        public int $lower,
        public int $upper,
    ) {}

    public function count(): int
    {
        return $this->upper - $this->lower;
    }

    public function contains(int $week): bool
    {
        return $week >= $this->lower && $week < $this->upper;
    }
}
