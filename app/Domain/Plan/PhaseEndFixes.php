<?php

namespace App\Domain\Plan;

/** The two fixes offered when a phase ends mid-mesocycle (PLAN-021). Either is null when it does not fit. */
final readonly class PhaseEndFixes
{
    /**
     * @param  ?int  $movedEndWeek  fix 1: the new exclusive end week, at the nearest deload within 2 weeks
     * @param  ?MesocycleLengthChange  $mesocycleLength  fix 2: a mesocycle length that makes its deload the phase's last week
     */
    public function __construct(
        public ?int $movedEndWeek,
        public ?MesocycleLengthChange $mesocycleLength,
    ) {}
}
