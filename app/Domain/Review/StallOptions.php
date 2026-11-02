<?php

namespace App\Domain\Review;

/**
 * REV-027: the options shown when progress stalls during Fat loss. The diet break option is always shown
 * (REV-010 rules hide it elsewhere), so it is not listed here.
 */
final readonly class StallOptions
{
    /**
     * @param  float|null  $cutKcal  positive kcal per day; null when no cut can be offered (no cut amount, or SAFE-012 refuses it; SAFE-011 never gets here)
     * @param  int|null  $addSteps  steps per day; null when fewer than 1,000 can be added under 10,000
     */
    public function __construct(
        public ?float $cutKcal,
        public ?int $addSteps,
        public StallChange $recommended,
    ) {}
}
