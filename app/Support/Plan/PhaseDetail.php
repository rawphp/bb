<?php

namespace App\Support\Plan;

/** Where a phase stands in time (PLAN-004). Its weights are {@see PhaseWeights}. */
final readonly class PhaseDetail
{
    public function __construct(
        /** Length in weeks; null for a phase with no end (PH-005). */
        public ?int $weeks,
        /** 0 to 1; null for a phase with no end. */
        public ?float $progress,
        /** "Week 7 of 12", "Starts in 3 weeks" or "Finished". */
        public string $progressText,
        /** Today falls in its weeks. */
        public bool $isCurrent,
        /** Its weeks are all behind today. */
        public bool $isOver,
    ) {}
}
