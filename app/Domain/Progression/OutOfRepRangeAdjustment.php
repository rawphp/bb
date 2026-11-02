<?php

namespace App\Domain\Progression;

/** PROG-053: the next target weight moved one step, and why. */
final readonly class OutOfRepRangeAdjustment
{
    public function __construct(
        public OutOfRepRangeDirection $direction,
        public float $weight,
        public string $note,
    ) {}
}
