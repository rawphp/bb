<?php

namespace App\Domain\Model;

/** An inclusive range, lower to upper. */
final readonly class ClosedRange
{
    public function __construct(
        public int|float $lower,
        public int|float $upper,
    ) {}

    public function contains(int|float $value): bool
    {
        return $value >= $this->lower && $value <= $this->upper;
    }
}
