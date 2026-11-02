<?php

namespace App\Support\Plan;

/** PLAN-004: a phase's trend weights in kg; null until it has started and there are weigh-ins. */
final readonly class PhaseWeights
{
    public function __construct(
        public ?float $startKg,
        /** Where the rate takes the start weight by the end, diet-break weeks not counting (PLAN-025). */
        public ?float $targetKg,
        /** The trend weight now, or when the phase ended. */
        public ?float $currentKg,
    ) {}
}
