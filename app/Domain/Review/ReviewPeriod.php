<?php

namespace App\Domain\Review;

use App\Domain\Model\PlanWeek;

/** The two weeks a review is about (PLAN-008). */
final readonly class ReviewPeriod
{
    /**
     * @param  PlanWeek  $reviewed  the plan week that just ended, with the flags its row stores
     * @param  PlanWeek  $next  the week the review sets: the plan week that starts on the review day, the one today is in
     */
    public function __construct(
        public PlanWeek $reviewed,
        public int $reviewedIndex,
        public PlanWeek $next,
        public int $nextIndex,
    ) {}
}
