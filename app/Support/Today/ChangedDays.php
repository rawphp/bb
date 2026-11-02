<?php

namespace App\Support\Today;

use DateTimeImmutable;

/**
 * SCH-023, CAP-010: the days whose Today and week strip a write changed when it moves sessions, deloads or menus of
 * every later week (a restart, an end, a paused or un-paused away week). The client browses up to the snapshot's last
 * day (and always shows today's plan week), so the days run from where the change starts to whichever is later.
 * Read the snapshot after the write.
 */
final class ChangedDays
{
    /** @return list<string> Y-m-d, from `$from` through the last day the client can show */
    public static function from(TodaySnapshot $after, DateTimeImmutable $from): array
    {
        $to = max($after->lastDay() ?? $from, self::endOfCurrentWeek($after));
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /** @return list<string> Y-m-d, from the first day of the plan week in progress through the last day the client can show */
    public static function fromCurrentWeek(TodaySnapshot $after): array
    {
        $grid = $after->train->grid();
        $week = $grid?->weekIndexContaining($after->today());

        return self::from($after, $week === null ? $after->today() : $grid->start($week));
    }

    private static function endOfCurrentWeek(TodaySnapshot $after): DateTimeImmutable
    {
        $grid = $after->train->grid();
        $week = $grid?->weekIndexContaining($after->today());
        $days = $week === null ? [] : $grid->planWeek($week)->days();

        return $days === [] ? $after->today() : end($days);
    }
}
