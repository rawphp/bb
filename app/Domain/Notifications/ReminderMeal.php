<?php

namespace App\Domain\Notifications;

use App\Domain\Placement\ClockTime;

/** A day's meal, as the planner reads it (NOTIF-002, NOTIF-003). */
final readonly class ReminderMeal
{
    public function __construct(
        public string $id,
        public string $name,
        public ?ClockTime $time,
        public bool $isCheckedIn,
    ) {}
}
