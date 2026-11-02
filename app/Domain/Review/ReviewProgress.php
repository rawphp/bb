<?php

namespace App\Domain\Review;

use App\Domain\Model\ClosedRange;
use App\Domain\Review\ReviewProgress\Position;

/** REV-003 step 4: the trend weight against the goal range for the phase, and the week's rate against the target. */
final readonly class ReviewProgress
{
    /**
     * @param  ?float  $weekRatePercent  the week's change in trend, percent of bodyweight; null without a trend a week earlier
     * @param  ?bool  $rateIsOnTarget  TBL-050's band around the target rate; null in Maintenance, which has a corridor
     *                                 instead (REV-029), and null without a week rate
     */
    public function __construct(
        public float $trendKg,
        public ClosedRange $rangeKg,
        public Position $position,
        public ?float $weekRatePercent,
        public float $targetRatePercent,
        public ?bool $rateIsOnTarget,
    ) {}
}
