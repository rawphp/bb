<?php

namespace App\Domain\Review;

use App\Domain\Defaults\TBL051ReviewThresholds;
use App\Domain\Macros\MacroRules;
use App\Domain\Model\PlanWeek;
use App\Domain\Weight\TrendWeight;

/** REV-021, REV-022: expenditure measured from checked-in intake and the change in trend weight. */
final class MeasuredExpenditure
{
    /** REV-022: a week needs this many logged days. */
    public const MINIMUM_LOGGED_DAYS = 5;

    /** REV-021: the estimate averages this many eligible weeks (TBL-051). */
    public static function eligibleWeeksNeeded(): int
    {
        return count(TBL051ReviewThresholds::EXPENDITURE_WEEK_WEIGHTS_NEWEST_FIRST);
    }

    /** REV-022: enough logged days, not a diet break, deload or away week, and not the week straight after one. */
    public static function isEligible(ReviewWeek $week, ?ReviewWeek $previous): bool
    {
        return $week->loggedDays() >= self::MINIMUM_LOGGED_DAYS
            && ! $week->isBreakOrAway()
            && $previous?->isBreakOrAway() !== true;
    }

    /**
     * REV-021: average intake minus the trend change over the week in kcal per day.
     * The change runs from the day before the week to its last day. Null without intake or either trend point.
     */
    public static function weekKcal(ReviewWeek $week, TrendWeight $trend): ?float
    {
        $intake = $week->averageIntakeKcal();
        $end = $week->week->end();
        if ($intake === null || $end === null) {
            return null;
        }
        $before = $end->modify('-'.PlanWeek::LENGTH_IN_DAYS.' days');
        $endKg = $trend->trendKg($end);
        $beforeKg = $trend->trendKg($before);
        if ($endKg === null || $beforeKg === null) {
            return null;
        }

        return $intake - ($endKg - $beforeKg) * MacroRules::KCAL_PER_KG / PlanWeek::LENGTH_IN_DAYS;
    }

    /**
     * REV-021: the TBL-051 weighted average of the newest eligible weeks. An ineligible newest week changes
     * nothing (REV-022).
     *
     * @param  list<ReviewWeek>  $weeks  every finished week, oldest first, in any phase
     * @return float|null null with fewer eligible weeks than needed
     */
    public static function estimate(array $weeks, TrendWeight $trend): ?float
    {
        $eligible = [];
        foreach ($weeks as $index => $week) {
            $previous = $index > 0 ? $weeks[$index - 1] : null;
            if (! self::isEligible($week, $previous)) {
                continue;
            }
            $kcal = self::weekKcal($week, $trend);
            if ($kcal !== null) {
                $eligible[] = $kcal;
            }
        }
        $needed = self::eligibleWeeksNeeded();
        $newestFirst = array_reverse(array_slice($eligible, -$needed));
        if (count($newestFirst) !== $needed) {
            return null;
        }

        $sum = 0.0;
        foreach ($newestFirst as $position => $kcal) {
            $sum += $kcal * TBL051ReviewThresholds::EXPENDITURE_WEEK_WEIGHTS_NEWEST_FIRST[$position];
        }

        return $sum;
    }
}
