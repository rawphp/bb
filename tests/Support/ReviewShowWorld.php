<?php

namespace Tests\Support;

use App\Actions\Review\OpenReview;
use App\Capabilities\Reads\Review\ReviewShowPayload;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use Closure;
use RuntimeException;

/** The stored review world for the review_show builders: a ReviewSeed changed by the test, then analysed. */
final class ReviewShowWorld
{
    /** @param  ?Closure(ReviewSeed): void  $change */
    public static function analysis(?Closure $change = null, string $today = ReviewSeed::TODAY): ReviewAnalysis
    {
        $seed = new ReviewSeed;
        if ($change !== null) {
            $change($seed);
        }

        return $seed->analysis($today) ?? throw new RuntimeException('No review is open.');
    }

    /** A muscle-gain world on the plan's own rate, so the week's rate is on target (TBL-050). */
    public static function muscleGain(ReviewSeed $seed): void
    {
        $seed->phaseType = 'muscleGain';
        $seed->ratePercent = 0.25;
        $seed->weight = fn (int $offset): float => 90 + 0.0321 * ($offset + 28);
    }

    /** The amount review_show shows in the own-calories field for the user's stored answers today. */
    public static function shownOwnKcal(User $user): int
    {
        $analysis = ReviewAnalysis::for($user, ReviewSeed::TODAY) ?? throw new RuntimeException('No review is open.');

        return ReviewShowPayload::build($analysis, OpenReview::answers($user, $analysis))->toArray()['calories']['own']['value'];
    }
}
