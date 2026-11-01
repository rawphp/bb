<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/**
 * One phase of a plan (PLAN-002).
 *
 * A phase fills whole plan weeks: start is the first day of its first week and end is the first day of the week
 * after its last week (an exclusive boundary). end is null for a phase with no end (PH-005).
 */
final readonly class Phase
{
    /**
     * @param  float  $targetRatePercentPerWeek  signed, percent of bodyweight per week: positive gains, negative loses (as TBL-010)
     */
    public function __construct(
        public string $id,
        public PhaseType $type,
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public float $targetRatePercentPerWeek,
        public ?DateTimeImmutable $eventDate = null,
    ) {}

    /** The same phase on new dates: the one place that copies the other fields. */
    public function withDates(DateTimeImmutable $start, ?DateTimeImmutable $end): self
    {
        return new self($this->id, $this->type, $start, $end, $this->targetRatePercentPerWeek, $this->eventDate);
    }
}
