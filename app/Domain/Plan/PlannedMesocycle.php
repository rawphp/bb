<?php

namespace App\Domain\Plan;

/**
 * The grid slot (position and length) that a mesocycle fills. weeks includes the deload, which is its last week.
 * SCH-060: each paused week inside it stretches the slot by one week without counting as a mesocycle week.
 */
final readonly class PlannedMesocycle
{
    public function __construct(
        public int $startWeek,
        public int $weeks,
        public int $pausedWeeks = 0,
    ) {}

    public function deloadWeek(): int
    {
        return $this->nextStartWeek() - 1;
    }

    public function nextStartWeek(): int
    {
        return $this->startWeek + $this->weeks + $this->pausedWeeks;
    }

    public function contains(int $week): bool
    {
        return $week >= $this->startWeek && $week < $this->nextStartWeek();
    }
}
