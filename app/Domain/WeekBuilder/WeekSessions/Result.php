<?php

namespace App\Domain\WeekBuilder\WeekSessions;

use App\Domain\Placement\ScheduledSession;

/** The sessions of a plan week and the flags that decided them. */
final readonly class Result
{
    /**
     * @param  ?int  $mesocycleWeek  The week of the mesocycle, from 0; null outside it.
     * @param  list<ScheduledSession>  $scheduled  In week order; empty in a paused or non-mesocycle week.
     */
    public function __construct(
        public bool $isDeload,
        public ?int $mesocycleWeek,
        public bool $isPaused,
        public array $scheduled,
    ) {}
}
