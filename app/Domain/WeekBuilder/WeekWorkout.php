<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Model\DayType;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\WorkoutTime;

/** One session on a day of a built week. */
final readonly class WeekWorkout
{
    /**
     * @param  Session  $session  The deload version in a deload week (PROG-041, PROG-042).
     * @param  WorkoutTime  $time  The day's start time, with the planned length (SCH-046).
     * @param  DayType  $intensity  TBL-031: the session's intensity; Light in a deload week (NUT-023).
     */
    public function __construct(
        public Session $session,
        public WorkoutTime $time,
        public int $workingSets,
        public DayType $intensity,
    ) {}

    public function startingAt(ClockTime $start): self
    {
        return new self($this->session, new WorkoutTime($start, $this->time->lengthMinutes), $this->workingSets, $this->intensity);
    }

    public function runningFor(int $lengthMinutes, DayType $intensity): self
    {
        return new self($this->session, new WorkoutTime($this->time->start, $lengthMinutes), $this->workingSets, $intensity);
    }
}
