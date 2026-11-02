<?php

namespace App\Domain\Progress;

use App\Domain\Model\PhaseType;
use DateTimeImmutable;

/** One past mesocycle in the history (ANA-007). */
final readonly class MesocycleHistoryEntry
{
    /** @param  list<MuscleWeeklySets>  $weeklySets  in the order of MESO-033; muscles with no sets are left out */
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $start,
        /** Exclusive: the first day after the last week. */
        public DateTimeImmutable $end,
        /** The type of the phase it ran in, null when it has none. */
        public ?PhaseType $phaseType,
        /** Mesocycle weeks with at least one logged set: what the sets are averaged over. */
        public int $weeksWithSets,
        public array $weeklySets,
    ) {}
}
