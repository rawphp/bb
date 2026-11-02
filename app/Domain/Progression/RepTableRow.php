<?php

namespace App\Domain\Progression;

/** One row of a set's rep table: a weight in the user's unit and the reps for an equal effort at it (PROG-024). */
final readonly class RepTableRow
{
    public function __construct(
        public float $weight,
        public int $reps,
    ) {}
}
