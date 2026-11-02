<?php

namespace App\Domain\Notifications;

use App\Domain\Placement\ClockTime;

/** A day's planned session, as the planner reads it (NOTIF-003). */
final readonly class ReminderWorkout
{
    /** @param  bool  $isDone  started, done or skipped: nothing left to remind about */
    public function __construct(
        public string $name,
        public ClockTime $start,
        public bool $isDone,
    ) {}
}
