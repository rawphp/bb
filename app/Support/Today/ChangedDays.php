<?php

namespace App\Support\Today;

use DateTimeImmutable;

/**
 * SCH-023, CAP-010: the days whose Today and week strip a write changed when it moves sessions, deloads or menus of
 * every later week (a restart, an end, a paused or un-paused away week). The client browses to the whole week strip
 * around the snapshot's last day (TodaySnapshot::insidePlan opens any day of it) and always shows today's plan week,
 * so the days run from where the change starts to the end of whichever is later. Read the snapshot after the write.
 */
final class ChangedDays
{
    /** @return list<string> Y-m-d, from `$from` through the last day of the strip week the client can show */
    public static function from(TodaySnapshot $after, DateTimeImmutable $from): array
    {
        $lastDay = $after->lastDay();
        $to = max($lastDay === null ? $from : $after->weekStart($lastDay)->modify('+6 days'), self::endOfCurrentWeek($after));
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @param  ?DateTimeImmutable  $orEarlier  a day the change reaches back to (a restart's old start); the range starts
     *                                         at the earlier of its plan week and the plan week in progress
     * @return list<string> Y-m-d, from the first day of that plan week through the last day of the strip week the client can show
     */
    public static function fromCurrentWeek(TodaySnapshot $after, ?DateTimeImmutable $orEarlier = null): array
    {
        $grid = $after->train->grid();
        $week = $grid?->weekIndexContaining($after->today());
        $from = $week === null ? $after->today() : $grid->start($week);
        $earlier = $orEarlier === null ? null : $grid?->weekIndexContaining($orEarlier);

        return self::from($after, $earlier === null ? $from : min($from, $grid->start($earlier)));
    }

    private static function endOfCurrentWeek(TodaySnapshot $after): DateTimeImmutable
    {
        $grid = $after->train->grid();
        $week = $grid?->weekIndexContaining($after->today());
        $days = $week === null ? [] : $grid->planWeek($week)->days();

        return $days === [] ? $after->today() : end($days);
    }
}
