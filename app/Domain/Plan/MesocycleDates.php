<?php

namespace App\Domain\Plan;

use DateTimeImmutable;

/** A stored mesocycle's dates as any record reads them (MESO-043). A draft has no start and no weeks. */
final readonly class MesocycleDates
{
    /**
     * @param  ?DateTimeImmutable  $endedOn  MESO-043: the day it was ended early
     */
    public function __construct(
        public ?DateTimeImmutable $startDate,
        public ?int $weeks,
        public ?DateTimeImmutable $endedOn,
    ) {}

    /** The last day it runs; null for a draft, which has no dates. */
    public function lastDay(): ?DateTimeImmutable
    {
        if ($this->startDate === null || $this->weeks === null) {
            return null;
        }

        return MesocycleSpan::lastDay($this->startDate, $this->weeks, $this->endedOn);
    }

    /**
     * MESO-043: the last day an unstarted session is planned. Only a mesocycle that was ended has one; one that
     * runs its weeks is placed on the plan's weeks, which PLAN-008 can stretch past `start + weeks * 7`, so the
     * calendar must not cut it.
     */
    public function plannedLastDay(): ?DateTimeImmutable
    {
        return $this->endedOn === null ? null : $this->lastDay();
    }
}
