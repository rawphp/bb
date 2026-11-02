<?php

namespace App\Domain\Notifications;

use App\Domain\Placement\ClockTime;

/** A day's scheduled weigh-in, as the planner reads it (NOTIF-001). */
final readonly class ReminderWeighIn
{
    /** @param  bool  $isLogged  a weigh-in is already logged that day */
    public function __construct(
        public ClockTime $time,
        public bool $isLogged,
    ) {}
}
