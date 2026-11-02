<?php

namespace App\Domain\Placement;

use App\Domain\Model\Session;
use DateTimeImmutable;

/** A mesocycle session put on a date of a plan week. */
final readonly class ScheduledSession
{
    /**
     * @param  DateTimeImmutable  $date  Start of the day.
     * @param  bool  $isSkipped  SCH-053: the session was skipped; it keeps its place but trains nothing.
     */
    public function __construct(
        public DateTimeImmutable $date,
        public Session $session,
        public WorkoutTime $time,
        public bool $isSkipped = false,
    ) {}
}
