<?php

namespace App\Domain\WeekBuilder;

use DateTimeImmutable;

/** A stored workout session, as SessionRow::rows() reads it. */
final readonly class WorkoutSessionRecord
{
    /**
     * @param  int  $week  The mesocycle week, from 1.
     * @param  ?int  $day  The session's place among the mesocycle's days, from 1; null on a session saved without one.
     */
    public function __construct(
        public string $id,
        public ?string $mesocycleId,
        public int $week,
        public ?int $day,
        public bool $isSkipped = false,
        public ?DateTimeImmutable $plannedStartAt = null,
        public ?DateTimeImmutable $actualStartAt = null,
    ) {}
}
