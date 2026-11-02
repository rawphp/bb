<?php

namespace App\Domain\Review;

use App\Domain\Defaults\TBL023TargetRIR;
use App\Domain\Review\DeloadMove\Plan;

/** REV-011: a mesocycle shortened so its deload is next week. */
final readonly class DeloadMove
{
    /** @param  int  $weeks  The new length, deload included. */
    public function __construct(public int $weeks) {}

    /**
     * TBL-023 for the new length: the target RIR of each training week and then the deload's 0. The schedule follows
     * the length alone, so nothing else is stored.
     *
     * @return list<int>
     */
    public function rirByWeek(): array
    {
        return TBL023TargetRIR::rir($this->weeks - 1) ?? [];
    }

    /**
     * How Drop fatigue meets the deload.
     *
     * @param  ?int  $mesocycleWeeks  the length of the mesocycle covering the week that ended, deload included
     * @param  ?int  $mesocycleStartWeek  the plan week it started on
     * @param  int  $reviewedWeek  the plan week that ended
     * @param  int  $nextWeek  the plan week the review sets
     */
    public static function plan(
        ?int $mesocycleWeeks,
        ?int $mesocycleStartWeek,
        int $reviewedWeek,
        int $nextWeek,
        bool $nextIsDeload,
    ): Plan {
        if ($mesocycleWeeks === null || $mesocycleStartWeek === null || $nextIsDeload) {
            return Plan::dietBreakOnly();
        }
        $weeksDone = $reviewedWeek - $mesocycleStartWeek + 1;
        $move = new self($nextWeek - $mesocycleStartWeek + 1);
        if ($weeksDone < 1 || TBL023TargetRIR::rir($move->weeks - 1) === null) {
            return Plan::dietBreakOnly();
        }
        $offer = Fatigue::deloadOffer($mesocycleWeeks, $weeksDone);

        return $offer->askDeloadTooDefaultYes === null
            ? Plan::deloadNow($move)
            : Plan::askDeloadToo($offer->askDeloadTooDefaultYes, $move);
    }
}
