<?php

namespace Tests\Support;

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
}
