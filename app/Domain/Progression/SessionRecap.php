<?php

namespace App\Domain\Progression;

use DateTimeImmutable;

/** WO-011: what a finished session adds up to, and each exercise's best set against last week's. */
final class SessionRecap
{
    /**
     * The heaviest set, then of those the one with the most reps; null with none. A bodyweight set (no weight)
     * weighs nothing.
     *
     * @param  list<SetLoad>  $sets
     */
    public static function best(array $sets): ?SetLoad
    {
        $best = null;
        foreach ($sets as $set) {
            if ($best === null) {
                $best = $set;

                continue;
            }
            $weight = $set->weight ?? 0.0;
            $bestWeight = $best->weight ?? 0.0;
            if ($weight > $bestWeight || ($weight == $bestWeight && $set->reps > $best->reps)) {
                $best = $set;
            }
        }

        return $best;
    }

    /**
     * Weight times reps over the sets; a bodyweight set adds nothing.
     *
     * @param  list<SetLoad>  $sets
     */
    public static function lifted(array $sets): float
    {
        return (float) array_sum(array_map(fn (SetLoad $set) => ($set->weight ?? 0.0) * $set->reps, $sets));
    }

    /** The weight the best set moved on last week's best, else, at the same weight, the reps. */
    public static function change(SetLoad $now, SetLoad $before): BestSetChange
    {
        $weight = round(($now->weight ?? 0.0) - ($before->weight ?? 0.0), 2);

        return abs($weight) > 0.001 ? new BestSetChange($weight, 0) : new BestSetChange(0.0, $now->reps - $before->reps);
    }

    /** The whole minutes from a session's start to its end, never below none. */
    public static function minutes(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60));
    }
}
