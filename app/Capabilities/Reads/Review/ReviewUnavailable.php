<?php

namespace App\Capabilities\Reads\Review;

use App\Domain\Review\ReviewTiming;
use App\Domain\Review\StoredPlanWeek;
use App\Models\PlanWeek;
use App\Models\WeighIn;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Review\ReviewData;
use App\Support\Review\ReviewPlanContext;
use App\Support\Today\TodaySnapshot;

/** Why there is nothing to review (rp-clone WeeklyReviewModel.prepare), worked out as ReviewAnalysis::fromData does. */
final class ReviewUnavailable
{
    public static function message(ReviewData $data): string
    {
        $context = ReviewPlanContext::from($data);
        if ($context === null || $data->profile === null) {
            return 'There is no plan to review yet.';
        }
        $stored = $data->planWeeks->map(fn (PlanWeek $row) => new StoredPlanWeek(
            start: TrainSnapshot::day($row->start_date),
            isDeload: (bool) $row->is_deload,
            isDietBreak: (bool) $row->is_diet_break,
            isAway: (bool) $row->is_away,
            reviewedAt: TodaySnapshot::instant($row->reviewed_at),
        ))->values()->all();
        $times = $data->weighIns->map(fn (WeighIn $w) => TodaySnapshot::instant($w->weighed_at))->filter()->values()->all();
        $status = ReviewTiming::status($data->today, $context->grid, $stored, $times);

        return match (true) {
            $status->isDone() => 'This week\'s review is done.',
            ($context->grid->weekIndexContaining($data->today) ?? 0) > 0 => 'Log at least 2 weigh-ins in the week to review it.',
            default => 'There is no finished week to review yet.',
        };
    }
}
