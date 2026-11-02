<?php

namespace Tests\Unit\Domain\Review;

use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Review\ReviewStatus;
use App\Domain\Review\ReviewTiming;
use App\Domain\Review\StoredPlanWeek;
use DateTimeImmutable;
use Tests\Unit\Domain\Plan\PlanFixtures;

/** The helpers of rp-clone's ReviewTimingTests. The grid is PlanFixtures': weeks start on Mondays from 2026-01-05. */
final class ReviewTimingFixtures
{
    public static function date(int $day): DateTimeImmutable
    {
        return PlanFixtures::date(2026, 1, $day);
    }

    /** A weigh-in at 07:00 on a January day. */
    public static function weighIn(int $day): DateTimeImmutable
    {
        return self::date($day)->setTime(7, 0);
    }

    /**
     * @param  list<int>  $weighIns
     * @param  list<StoredPlanWeek>  $stored
     */
    public static function status(int $today, array $weighIns, array $stored = [], ?PlanWeekGrid $grid = null): ReviewStatus
    {
        return ReviewTiming::status(
            self::date($today), $grid ?? PlanFixtures::grid(), $stored, array_map(self::weighIn(...), $weighIns),
        );
    }
}
