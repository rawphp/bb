<?php

namespace App\Domain\Plan\MissedWeeks;

/** The flags of the plan week starting `$from` (Y-m-d) moving to the one starting `$to` when its mesocycle restarts (SCH-058). */
final readonly class FlagMove
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
