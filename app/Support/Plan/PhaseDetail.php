<?php

namespace App\Support\Plan;

/** A phase as its detail shows it (PLAN-004). Weights are kg; null until the phase has started and has weigh-ins. */
final readonly class PhaseDetail
{
    public function __construct(
        /** Length in weeks; null for a phase with no end (PH-005). */
        public ?int $weeks,
        /** 0 to 1; null for a phase with no end. */
        public ?float $progress,
        /** "Week 7 of 12", "Starts in 3 weeks" or "Finished". */
        public string $progressText,
        public ?float $startKg,
        /** Where the rate takes the start weight by the end, diet-break weeks not counting (PLAN-025). */
        public ?float $targetKg,
        /** The trend weight now, or when the phase ended. */
        public ?float $currentKg,
        /** Today falls in its weeks. */
        public bool $isCurrent,
        /** Its weeks are all behind today. */
        public bool $isOver,
    ) {}
}
