<?php

namespace App\Domain\Review;

/** What "Repeat this week" keeps (REV-010, REV-002). */
final class RepeatThisWeek
{
    /**
     * The average daily calories to repeat: the week that ended. After a diet break, deload or away week it is the
     * last ordinary week's, since that week's calories are not a level to hold (REV-023: straight back).
     *
     * @param  list<ReviewWeek>  $weeks  every finished week, oldest first; the newest is the week that ended
     */
    public static function targetKcal(array $weeks): ?float
    {
        if ($weeks === []) {
            return null;
        }
        $reviewed = $weeks[count($weeks) - 1];
        if (! $reviewed->isBreakOrAway()) {
            return $reviewed->targetKcal;
        }

        $lastOrdinary = self::lastOrdinaryBeforeNewest($weeks);

        return $lastOrdinary === null ? $reviewed->targetKcal : $lastOrdinary->targetKcal;
    }

    /**
     * The newest week before the newest that is not a break, deload or away week.
     *
     * @param  list<ReviewWeek>  $weeks  oldest first
     */
    public static function lastOrdinaryBeforeNewest(array $weeks): ?ReviewWeek
    {
        for ($i = count($weeks) - 2; $i >= 0; $i--) {
            if (! $weeks[$i]->isBreakOrAway()) {
                return $weeks[$i];
            }
        }

        return null;
    }
}
