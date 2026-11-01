<?php

namespace App\Domain\Plan\MissedWeeks;

/**
 * A plan week as the current mesocycle placed it: how many sessions, and how many of them were started (SCH-058).
 * A skipped session counts as placed and not started.
 */
final readonly class Week
{
    public function __construct(
        public int $index,
        public int $sessions,
        public int $started,
    ) {}
}
