<?php

namespace App\Domain\Phases;

use App\Domain\Model\PhaseType;

/** PH-030: what the phase achieved. */
final readonly class PhaseSummary
{
    /** @param  float  $adherence  0 to 1 */
    public function __construct(
        public PhaseType $type,
        public float $startTrendWeightKg,
        public float $endTrendWeightKg,
        public int $weeks,
        public float $adherence,
        public int $mesocyclesCompleted,
    ) {}

    public function weightChangeKg(): float
    {
        return $this->endTrendWeightKg - $this->startTrendWeightKg;
    }

    /** Signed, percent of start bodyweight per week. */
    public function weeklyRatePercent(): float
    {
        if ($this->weeks <= 0 || $this->startTrendWeightKg <= 0) {
            return 0;
        }

        return $this->weightChangeKg() / $this->startTrendWeightKg / $this->weeks * 100;
    }
}
