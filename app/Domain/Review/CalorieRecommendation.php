<?php

namespace App\Domain\Review;

use App\Domain\Phases\StartingCalories;

final readonly class CalorieRecommendation
{
    /**
     * @param  float  $kcal  next week's average daily calories
     * @param  float  $changeKcal  $kcal minus this week's target
     * @param  StartingCalories  $expenditure  the expenditure used: measured (REV-021) or the formula (NUT-002)
     */
    public function __construct(
        public float $kcal,
        public float $changeKcal,
        public RecommendationReason $reason,
        public StartingCalories $expenditure,
    ) {}
}
