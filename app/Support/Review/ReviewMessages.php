<?php

namespace App\Support\Review;

use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Review\DropFatigueReason;
use App\Domain\Review\FatigueAdvice;
use App\Domain\Review\MeasuredExpenditure;
use App\Domain\Review\RecommendationReason;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\StepPlanOffer;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekBuildRefusalReason;

/**
 * The words the review gives the rules' answers (rp-clone ReviewMessages): the spec's own where it has them
 * (REV-026, REV-027, REV-041, REV-046, SCH-064), and plain statements of the reason elsewhere.
 */
final class ReviewMessages
{
    /** REV-041 to REV-043. */
    public static function fatigue(FatigueAdvice $advice, int $weeksSinceBreak): string
    {
        return match ($advice->dropFatigue) {
            DropFatigueReason::TrainingFatigue => $advice->message() ?? '',
            DropFatigueReason::WeeksSinceBreak => "You have been in a deficit for {$weeksSinceBreak} weeks without a diet break or deload.",
            null => 'Your training fatigue stayed high after a diet break and a deload. '
                .'Consider ending fat loss and moving to Maintenance.',
        };
    }

    /** Why the review recommends what it does. The stall and water texts are the spec's (REV-026, REV-027). */
    public static function reason(RecommendationReason $reason, float $targetKcal): string
    {
        return match ($reason->kind) {
            RecommendationReasonKind::Stalled, RecommendationReasonKind::WaterWeight => $reason->message() ?? '',
            RecommendationReasonKind::PoorAdherence => self::poorAdherence($reason, $targetKcal),
            RecommendationReasonKind::TowardTargetRate => 'Your rate is off target, so calories move toward it.',
            RecommendationReasonKind::ChangeTooSmall => 'The change would be under 50 kcal, so calories stay as they are.',
            RecommendationReasonKind::OnTarget => 'You are on target, so calories stay as they are.',
            RecommendationReasonKind::InsideCorridor => 'Your trend weight is inside its corridor, so calories stay as they are.',
            RecommendationReasonKind::OutsideCorridor => 'Your trend weight is outside its corridor, so calories steer it back.',
            RecommendationReasonKind::GainStalled => 'Your gain has stalled, so calories go up.',
            RecommendationReasonKind::GainTooFast => 'You are gaining faster than planned, so calories come down.',
            RecommendationReasonKind::ReturnFromBreak => 'After a break, calories go straight back to your last ordinary target.',
            RecommendationReasonKind::FirstWeekOfPhase => 'The first week of a phase is left out of the rate, so calories hold.',
            RecommendationReasonKind::NoTrend => 'There is not enough trend weight yet to read this week, so calories hold.',
            RecommendationReasonKind::Safety => $reason->refusal?->reason === TargetRefusalReason::BmiAtOrBelowFloor
                ? 'Your BMI is too low to plan a cut, so calories rise to maintenance.'
                : 'A cut would take your fat or carbs below the safe minimum, so calories hold.',
            RecommendationReasonKind::LosingTooFast => 'You\'re losing faster than planned and your lifts are paying for it.',
            RecommendationReasonKind::RoughWeek => 'Rough week. Holding training and calories.',
        };
    }

    /** REV-025: the days logged when too few were, else the average eaten against the target. */
    private static function poorAdherence(RecommendationReason $reason, float $targetKcal): string
    {
        $advice = 'Hit your targets first, then the app adjusts.';
        $loggedDays = (int) $reason->loggedDays;
        if ($loggedDays < MeasuredExpenditure::MINIMUM_LOGGED_DAYS || $reason->averageKcal === null) {
            return "You logged {$loggedDays} of 7 days. {$advice}";
        }

        return 'You averaged '.self::kcal($reason->averageKcal).' kcal against a target of '.self::kcal($targetKcal).' kcal. '.$advice;
    }

    /** REV-050. */
    public static function steps(StepPlanOffer $offer): string
    {
        return 'You walked '.self::kcal($offer->actualAverage).' steps a day on average against a plan of '
            .self::kcal($offer->plannedAverage).'. Update your step plan?';
    }

    /** SAFE-011, SAFE-012 and the other ways next week can be refused. */
    public static function refusal(WeekBuildRefusal $refusal): string
    {
        return match ($refusal->reason) {
            WeekBuildRefusalReason::NoPhase => 'No phase covers next week, so its targets can\'t be planned.',
            WeekBuildRefusalReason::Targets => $refusal->targets?->reason === TargetRefusalReason::BmiAtOrBelowFloor
                ? 'Your BMI is too low for calories below maintenance. Choose maintenance or more.'
                : 'That is too few calories to fit your protein, fat and carbs. Choose a higher average.',
            WeekBuildRefusalReason::MealsDoNotFit => 'Your eating window can\'t hold your meals around your busy periods and workouts.',
            WeekBuildRefusalReason::Move => 'A day stays between 80% and 125% of the week\'s average, and a day already past can\'t change.',
        };
    }

    /** 2400 reads "2,400": en_US grouping, rounded half away from zero. */
    public static function kcal(float $value): string
    {
        return number_format(round($value));
    }

    public static function number(int $value): string
    {
        return self::kcal($value);
    }
}
