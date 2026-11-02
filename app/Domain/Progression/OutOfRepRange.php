<?php

namespace App\Domain\Progression;

use App\Domain\Defaults\TBL051ReviewThresholds;

/** PROG-053: reps outside the exercise's range move the next target weight one step. */
final class OutOfRepRange
{
    /** Which side of the range most sets fell on, or null when most sets did not fall on one side. */
    public static function direction(LoggedExercise $exercise): ?OutOfRepRangeDirection
    {
        $range = $exercise->repRange;
        if (Most::of($exercise->sets, fn (LoggedSet $set) => $set->reps < $range->lower)) {
            return OutOfRepRangeDirection::Below;
        }
        if (Most::of($exercise->sets, fn (LoggedSet $set) => $set->reps > $range->upper)) {
            return OutOfRepRangeDirection::Above;
        }

        return null;
    }

    /**
     * The next target weight moved one $step toward the range, when the exercise's latest sessions running
     * (TBL-051) were all out of range the same way. Null when there is nothing to move: in range, no step, or
     * already at zero.
     *
     * @param  list<LoggedExercise>  $history  oldest first
     */
    public static function adjustment(array $history, float $nextTargetWeight, float $step): ?OutOfRepRangeAdjustment
    {
        $sessions = TBL051ReviewThresholds::OUT_OF_REP_RANGE_SESSIONS_RUNNING;
        $latest = array_slice($history, -$sessions);
        if ($step <= 0 || count($latest) !== $sessions) {
            return null;
        }
        $direction = self::direction($latest[0]);
        if ($direction === null || ! array_all($latest, fn (LoggedExercise $exercise) => self::direction($exercise) === $direction)) {
            return null;
        }

        $range = $latest[count($latest) - 1]->repRange;
        $span = "{$range->lower} to {$range->upper}";

        if ($direction === OutOfRepRangeDirection::Below) {
            if ($nextTargetWeight <= 0) {
                return null;
            }

            return new OutOfRepRangeAdjustment(
                $direction, max(0.0, $nextTargetWeight - $step),
                "Your reps were below {$span} on most sets for {$sessions} sessions, so the weight goes down one step.",
            );
        }

        return new OutOfRepRangeAdjustment(
            $direction, $nextTargetWeight + $step,
            "Your reps were above {$span} on most sets for {$sessions} sessions, so the weight goes up one step.",
        );
    }
}
