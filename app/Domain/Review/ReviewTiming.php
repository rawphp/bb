<?php

namespace App\Domain\Review;

use App\Domain\Model\PlanWeek;
use App\Domain\Plan\PlanWeekGrid;
use DateTimeImmutable;

/** REV-001, REV-002, SCH-061: when the weekly review can be done. */
final class ReviewTiming
{
    /** REV-001: the week that ended needs at least this many weigh-ins. */
    public const MINIMUM_WEIGH_INS = 2;

    /**
     * The review of the most recent finished week, which is the plan week before the one today is in. Only that
     * week is modelled: when a new week starts, an unreviewed one is no longer offered.
     *
     * REV-001: available from day 1 of the new week once the week that ended has 2 weigh-ins (SCH-061: an away week
     * needs none). REV-002: it stays available however long after day 1; "done" is the week's `reviewed_at`.
     * Days are read in the time zone of the plan start; a stored row matches its week by its date.
     *
     * @param  list<StoredPlanWeek>  $storedWeeks
     * @param  list<DateTimeImmutable>  $weighInTimes
     */
    public static function status(DateTimeImmutable $today, PlanWeekGrid $grid, array $storedWeeks, array $weighInTimes): ReviewStatus
    {
        $nextIndex = $grid->weekIndexContaining($today);
        if ($nextIndex === null || $nextIndex <= 0) {
            return ReviewStatus::unavailable();
        }
        $reviewedIndex = $nextIndex - 1;
        $reviewed = self::flagged($grid->planWeek($reviewedIndex), $storedWeeks);
        $period = new ReviewPeriod(
            $reviewed, $reviewedIndex, self::flagged($grid->planWeek($nextIndex), $storedWeeks), $nextIndex,
        );
        if (self::stored($reviewed, $storedWeeks)?->reviewedAt !== null) {
            return ReviewStatus::done($period);
        }
        if ($reviewed->isAway) {
            return ReviewStatus::available($period);
        }
        $zone = $reviewed->start->getTimezone();
        $days = array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $reviewed->days());
        $count = count(array_filter(
            $weighInTimes,
            fn (DateTimeImmutable $time) => in_array($time->setTimezone($zone)->format('Y-m-d'), $days, true),
        ));

        return $count >= self::MINIMUM_WEIGH_INS ? ReviewStatus::available($period) : ReviewStatus::unavailable();
    }

    /** @param  list<StoredPlanWeek>  $storedWeeks */
    private static function stored(PlanWeek $week, array $storedWeeks): ?StoredPlanWeek
    {
        $day = $week->start->format('Y-m-d');
        foreach ($storedWeeks as $row) {
            if ($row->start->format('Y-m-d') === $day) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The week with the flags its stored row has, when it has one.
     *
     * @param  list<StoredPlanWeek>  $storedWeeks
     */
    private static function flagged(PlanWeek $week, array $storedWeeks): PlanWeek
    {
        $row = self::stored($week, $storedWeeks);

        return $row === null ? $week : new PlanWeek($week->start, $week->dayCount, $row->isDeload, $row->isDietBreak, $row->isAway);
    }
}
