<?php

namespace App\Domain\Progression;

/** One logged working set: what it aimed for and what was done. */
final readonly class LoggedSet
{
    public function __construct(
        public float $weight,
        public int $targetReps,
        public int $reps,
    ) {}
}
