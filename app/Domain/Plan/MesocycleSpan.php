<?php

namespace App\Domain\Plan;

use DateTimeImmutable;

/**
 * How long a mesocycle runs (MESO-001, MESO-043). Days are calendar days in the zone of `$start`. With a plan its
 * weeks are the plan's weeks (PLAN-008), which a short first week makes shorter than 7 days: {@see lastDayOnGrid()}.
 */
final class MesocycleSpan
{
    /**
     * The last day it runs on 7-day weeks from its start, as without a plan: the end of its `$weeks`, or the day
     * before it was ended early if that is sooner. Sessions logged on later days stay; nothing unstarted is planned
     * after it. With a plan, {@see lastDayOnGrid()} applies.
     */
    public static function lastDay(DateTimeImmutable $start, int $weeks, ?DateTimeImmutable $endedOn): DateTimeImmutable
    {
        $first = $start->setTime(0, 0);

        return self::endedSooner($first->modify(sprintf('%+d days', $weeks * 7 - 1)), $endedOn);
    }

    /**
     * The last day it runs on the plan's grid: the end of the plan week holding its last week, or the day before it
     * was ended early if that is sooner. Week 1 is the plan week holding the start; before the plan, weeks run 7 days
     * back from the plan's start.
     */
    public static function lastDayOnGrid(DateTimeImmutable $start, int $weeks, ?DateTimeImmutable $endedOn, PlanWeekGrid $grid): DateTimeImmutable
    {
        $after = $grid->weekIndexCountingBack($start) + $weeks;
        $nextWeekStart = $after >= 0 ? $grid->start($after) : $grid->start(0)->modify(sprintf('%+d days', 7 * $after));

        return self::endedSooner($nextWeekStart->modify('-1 day'), $endedOn);
    }

    /** The day before `$endedOn`, read on the calendar of `$last`, when it comes before `$last`. */
    private static function endedSooner(DateTimeImmutable $last, ?DateTimeImmutable $endedOn): DateTimeImmutable
    {
        if ($endedOn === null) {
            return $last;
        }
        $ended = $endedOn->setTimezone($last->getTimezone());
        $dayBeforeEnd = $last
            ->setDate((int) $ended->format('Y'), (int) $ended->format('n'), (int) $ended->format('j'))
            ->modify('-1 day');

        return min($last, $dayBeforeEnd);
    }
}
