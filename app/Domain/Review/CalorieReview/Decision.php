<?php

namespace App\Domain\Review\CalorieReview;

use App\Domain\Review\RecommendationReason;

/** @internal One week's calorie decision, before it becomes a CalorieRecommendation. */
final readonly class Decision
{
    public function __construct(
        public float $kcal,
        public RecommendationReason $reason,
    ) {}
}
