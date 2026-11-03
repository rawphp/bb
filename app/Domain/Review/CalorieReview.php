<?php

namespace App\Domain\Review;

use App\Domain\Macros\WeekTargets;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\Phase;
use App\Domain\Model\Profile;
use App\Domain\Phases\StartingCalories;
use App\Domain\Review\CalorieReview\Review;
use App\Domain\Weight\TrendWeight;

/** REV-020 to REV-032: the weekly review's calorie recommendation. */
final class CalorieReview
{
    /** REV-023: the most a week's target moves. */
    public const MAX_CHANGE_KCAL = 250.0;

    /** REV-023: no change when it would be under this. */
    public const DEAD_ZONE_KCAL = 50.0;

    /** REV-025: average consumed more than this share off target is poor adherence. */
    public const ADHERENCE_TOLERANCE_SHARE = 0.10;

    /** REV-027: a week stalls when the trend moves less than this share of the target rate. */
    public const STALL_SHARE_OF_TARGET_RATE = 0.25;

    /** REV-026: a weigh-in this many percent above the one before. */
    public const WATER_JUMP_PERCENT = 1.0;

    /** REV-026: and back to the earlier weight within this many days. */
    public const WATER_REVERSAL_DAYS = 3;

    /** REV-027, SAFE-015: steps are never recommended past this many a day. */
    public const MAX_RECOMMENDED_STEPS = 10000;

    /** REV-027: steps are added in this range per day. */
    public static function stepIncreaseRange(): ClosedRange
    {
        return new ClosedRange(1000, 2000);
    }

    /**
     * @param  list<ReviewWeek>  $weeks  every finished week, oldest first; the newest is the week just reviewed
     * @param  WeekTargets  $currentTargets  the reviewed week's targets, used only to check the safety floors (REV-030)
     * @param  Profile  $profile  weightKg should be the trend weight
     * @param  float  $formulaExpenditureKcal  NUT-002's average daily expenditure for next week
     * @param  int  $plannedDailySteps  the step goal now, for REV-027
     * @return CalorieRecommendation a value only: nothing is applied until the user chooses it (REV-031)
     */
    public static function recommend(
        Phase $phase,
        array $weeks,
        TrendWeight $trend,
        WeekTargets $currentTargets,
        Profile $profile,
        float $formulaExpenditureKcal,
        int $plannedDailySteps,
    ): CalorieRecommendation {
        $measured = MeasuredExpenditure::estimate($weeks, $trend);
        $expenditure = $measured !== null ? StartingCalories::measured($measured) : StartingCalories::formula($formulaExpenditureKcal);
        if ($weeks === []) {
            return new CalorieRecommendation($currentTargets->dailyAverageKcal(), 0, RecommendationReason::noTrend(), $expenditure);
        }
        $week = $weeks[count($weeks) - 1];
        $review = new Review(
            $phase, $weeks, $week, $trend, $currentTargets, $profile, $expenditure->kcal, $plannedDailySteps,
            $formulaExpenditureKcal,
        );
        $decision = $review->decide();

        return new CalorieRecommendation(
            $decision->kcal, $decision->kcal - $week->targetKcal, $decision->reason, $expenditure,
        );
    }
}
