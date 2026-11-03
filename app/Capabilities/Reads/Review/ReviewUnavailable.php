<?php

namespace App\Capabilities\Reads\Review;

use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewData;
use App\Support\Review\ReviewPlanContext;

/** Why there is nothing to review (rp-clone WeeklyReviewModel.prepare): the message for the status ReviewAnalysis reads. */
final class ReviewUnavailable
{
    public static function message(ReviewData $data): string
    {
        $status = ReviewAnalysis::status($data);
        if ($status === null) {
            return 'There is no plan to review yet.';
        }

        return match (true) {
            $status->isDone() => 'This week\'s review is done.',
            (ReviewPlanContext::from($data)?->grid->weekIndexContaining($data->today) ?? 0) > 0 => 'Log at least 2 weigh-ins in the week to review it.',
            default => 'There is no finished week to review yet.',
        };
    }
}
