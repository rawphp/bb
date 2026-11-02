<?php

namespace App\Domain\Plan;

/** A new length for one mesocycle (PLAN-021 fix 2). */
final readonly class MesocycleLengthChange
{
    public function __construct(
        public int $index,
        public int $weeks,
    ) {}
}
