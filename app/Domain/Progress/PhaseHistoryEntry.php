<?php

namespace App\Domain\Progress;

use App\Domain\Model\PhaseType;
use DateTimeImmutable;

/** One past phase in the history (ANA-006), with the mesocycles run in it (ANA-008). */
final readonly class PhaseHistoryEntry
{
    /** @param  list<ProgressMesocycle>  $mesocycles  the non-draft mesocycles of this phase, oldest first */
    public function __construct(
        public string $id,
        public PhaseType $type,
        public DateTimeImmutable $start,
        /** Exclusive: the first day after the phase. */
        public DateTimeImmutable $end,
        /** Trend weight on the phase's last day less the trend weight at its start. null without trend data. */
        public ?float $weightChangeKg,
        /** The change as percent of the start trend weight, per week. null without trend data. */
        public ?float $averageRatePercentPerWeek,
        public array $mesocycles,
    ) {}
}
