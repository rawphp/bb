<?php

namespace App\Domain\WeekBuilder\WeekSessions;

/** A workout session logged for a mesocycle day, by week and day number from 1. */
final readonly class LoggedDay
{
    public function __construct(
        public int $week,
        public int $day,
    ) {}
}
