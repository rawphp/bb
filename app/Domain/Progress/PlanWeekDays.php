<?php

namespace App\Domain\Progress;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Weekdays and weeks as the plan keeps them. The server stores weekdays as ISO numbers (1 = Monday ... 7 = Sunday).
 * A "calendar" weekday is Swift's (1 = Sunday ... 7 = Saturday), as PlanWeekGrid uses.
 */
final class PlanWeekDays
{
    public static function isoWeekday(DateTimeImmutable $day, DateTimeZone $zone): int
    {
        return (int) $day->setTimezone($zone)->format('N');
    }

    /** The ISO weekday (1 = Monday ... 7 = Sunday) of a calendar weekday (1 = Sunday ... 7 = Saturday). */
    public static function isoWeekdayFromCalendar(int $weekday): int
    {
        return ($weekday + 5) % 7 + 1;
    }

    /** The calendar weekday (1 = Sunday ... 7 = Saturday) of an ISO weekday (1 = Monday ... 7 = Sunday). */
    public static function calendarWeekdayFromIso(int $weekday): int
    {
        return $weekday % 7 + 1;
    }

    /**
     * SCH-040: ISO weekdays in plan-week order, the week starting on the review day.
     *
     * @param  list<int>  $weekdays
     * @return list<int>
     */
    public static function inPlanWeekOrder(array $weekdays, int $reviewIsoWeekday): array
    {
        usort($weekdays, fn (int $a, int $b) => ($a - $reviewIsoWeekday + 7) % 7 <=> ($b - $reviewIsoWeekday + 7) % 7);

        return $weekdays;
    }

    /**
     * ANA-003: the 7 days of the current plan week. It starts on the latest review weekday (ISO) on or before
     * today (the plan's current review day), so a review day that is today starts the week today.
     *
     * @return list<DateTimeImmutable>
     */
    public static function currentWeek(DateTimeImmutable $today, int $reviewWeekday, DateTimeZone $zone): array
    {
        $todayStart = Days::startOf($today, $zone);
        $back = (self::isoWeekday($todayStart, $zone) - $reviewWeekday + 7) % 7;
        $start = $todayStart->modify("-{$back} days");

        return array_map(fn (int $i) => Days::startOf($start->modify("+{$i} days"), $zone), range(0, 6));
    }
}
