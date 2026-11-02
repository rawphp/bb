<?php

namespace App\Support\Today;

use App\Domain\Model\Session;
use App\Domain\Placement\ClockTime;
use App\Models\Mesocycle;
use DateTimeImmutable;

/** The mesocycle session the plan puts on a day (SCH-003, SCH-040, SCH-046). */
final readonly class TodayWorkoutItem
{
    public const PLANNED = 'planned';

    public const STARTED = 'started';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    /**
     * @param  int  $week  The mesocycle week, from 1.
     * @param  int  $day  The session's place in its week, from 1.
     * @param  ?int  $setsCompleted  The working sets logged once the session is done.
     */
    public function __construct(
        public DateTimeImmutable $date,
        public Mesocycle $mesocycle,
        public int $week,
        public int $day,
        public Session $session,
        public ClockTime $time,
        public int $minutes,
        public string $state,
        public ?int $setsCompleted,
    ) {}

    public function workingSets(): int
    {
        return array_sum(array_map(fn ($slot) => $slot->sets, $this->session->slots));
    }

    /** SCH-051: started, done or skipped; a session that is none of these can still be moved or re-timed. */
    public function isLogged(): bool
    {
        return $this->state !== self::PLANNED;
    }
}
