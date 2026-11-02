<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Placement\ScheduledSession;

/** What the plan puts on one date: its plan week, whether that week is a deload, and the session placed on it. */
final readonly class DaySessionPlan
{
    /**
     * @param  int  $weekIndex  The plan week (PLAN-008) the date falls in, from 0.
     * @param  ?int  $mesocycleWeek  The week of the mesocycle the date falls in, from 0; null outside it.
     * @param  bool  $isDeload  PLAN-022, SCH-060: the mesocycle's last week, a flagged week, or an away week that deloads.
     * @param  bool  $isPaused  SCH-060: an away week that pauses training.
     * @param  ?ScheduledSession  $session  The session placed on the date, already deloaded in a deload week.
     * @param  ?int  $sessionNumber  The session's place among the week's sessions, from 1.
     */
    public function __construct(
        public int $weekIndex,
        public ?int $mesocycleWeek,
        public bool $isDeload,
        public bool $isPaused,
        public ?ScheduledSession $session,
        public ?int $sessionNumber,
    ) {}
}
