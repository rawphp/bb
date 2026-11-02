<?php

namespace App\Domain\Review;

use App\Domain\Defaults\TBL050OnTargetBand;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Progress\GoalRange;
use App\Domain\Review\ReviewProgress\Position;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;

final class ReviewProgressRules
{
    /** Days are read in the time zone of `$weekEnd`. Null without a goal range or a trend on that day. */
    public static function make(Phase $phase, TrendWeight $trend, DateTimeImmutable $weekEnd): ?ReviewProgress
    {
        $goal = GoalRange::make($phase, $trend, $weekEnd->getTimezone());
        $trendKg = $trend->trendKg($weekEnd);
        if ($goal === null || $trendKg === null) {
            return null;
        }
        $point = $goal->point($weekEnd);
        $position = $trendKg < $point->lowerKg ? Position::Below : ($trendKg > $point->upperKg ? Position::Above : Position::Within);
        $rate = $trend->weeklyRatePercent($weekEnd);
        $target = $phase->targetRatePercentPerWeek;
        $onTarget = null;
        if ($phase->type !== PhaseType::Maintenance && $rate !== null) {
            $band = max(
                TBL050OnTargetBand::ABSOLUTE_PERCENT_BODYWEIGHT_PER_WEEK,
                TBL050OnTargetBand::RELATIVE_PERCENT_OF_TARGET_RATE / 100 * abs($target),
            );
            $onTarget = abs($rate - $target) <= $band;
        }

        return new ReviewProgress(
            $trendKg, new ClosedRange($point->lowerKg, $point->upperKg), $position, $rate, $target, $onTarget,
        );
    }
}
