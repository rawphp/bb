<?php

namespace App\Domain\Review;

/** REV-050: the offer to update the step plan. */
final readonly class StepPlanOffer
{
    public function __construct(
        public float $actualAverage,
        public float $plannedAverage,
    ) {}
}
