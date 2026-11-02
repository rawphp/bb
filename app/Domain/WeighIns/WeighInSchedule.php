<?php

namespace App\Domain\WeighIns;

use DateTimeImmutable;

/**
 * The weigh-in schedule (WT-001, WT-004, WT-007): entries of ISO weekdays (1 is Monday) with a reminder time, at
 * least 2 different weekdays in all. Ported from rp-clone's WeighInScheduleModel and WeighInsModel.
 */
final class WeighInSchedule
{
    /** WT-001, WT-007. */
    public const MINIMUM_DAYS = 2;

    /** ISO weekday number => [short, full]. */
    public const WEEKDAYS = [
        1 => ['Mon', 'Monday'],
        2 => ['Tue', 'Tuesday'],
        3 => ['Wed', 'Wednesday'],
        4 => ['Thu', 'Thursday'],
        5 => ['Fri', 'Friday'],
        6 => ['Sat', 'Saturday'],
        7 => ['Sun', 'Sunday'],
    ];

    /** WT-001: the time is not a deadline. */
    public const TIME_NOTE = 'The time only sets your reminder. You can weigh in at any time.';

    /** WT-007. */
    public const DELETE_BLOCKED = 'Deleting this would leave fewer than 2 weigh-in days a week.';

    /**
     * "Mon, Wed, Fri", or "Every day".
     *
     * @param  list<int>  $weekdays
     */
    public static function daysLabel(array $weekdays): string
    {
        $days = self::days([$weekdays]);
        if (count($days) === 7) {
            return 'Every day';
        }

        return implode(', ', array_map(fn (int $day) => self::WEEKDAYS[$day][0], $days));
    }

    /**
     * "Schedule: 4 days a week", counting each weekday once however many entries hold it.
     *
     * @param  list<list<int>>  $entries  each entry's weekdays
     */
    public static function summary(array $entries): string
    {
        $count = count(self::days($entries));

        return match ($count) {
            0 => 'No weigh-in schedule yet',
            1 => 'Schedule: 1 day a week',
            default => "Schedule: {$count} days a week",
        };
    }

    /**
     * WT-007: an entry may be deleted only when the others still hold 2 different weekdays.
     *
     * @param  list<list<int>>  $entries  each entry's weekdays
     */
    public static function canDelete(array $entries, int $index): bool
    {
        $others = $entries;
        unset($others[$index]);

        return count(self::days(array_values($others))) >= self::MINIMUM_DAYS;
    }

    /**
     * WT-004: the scheduled days with no weigh-in, newest first, each at the start of its day. They run from the
     * first weigh-in's day up to yesterday: nothing before the first weigh-in counts, and today is not over yet.
     * A weigh-in counts for the calendar day of its own wall-clock time.
     *
     * @param  list<int>  $weekdays  the scheduled ISO weekdays
     * @param  list<DateTimeImmutable>  $weighedAt  every weigh-in's time
     * @return list<DateTimeImmutable>
     */
    public static function missedDays(array $weekdays, array $weighedAt, DateTimeImmutable $today): array
    {
        if ($weekdays === [] || $weighedAt === []) {
            return [];
        }

        $weighed = array_flip(array_map(fn (DateTimeImmutable $at) => $at->format('Y-m-d'), $weighedAt));
        $day = min($weighedAt)->setTime(0, 0);
        $end = $today->setTime(0, 0);
        $missed = [];
        while ($day < $end) {
            if (in_array((int) $day->format('N'), $weekdays, true) && ! isset($weighed[$day->format('Y-m-d')])) {
                $missed[] = $day;
            }
            $day = $day->modify('+1 day');
        }

        return array_reverse($missed);
    }

    /**
     * The different weekdays the entries hold together.
     *
     * @param  list<list<int>>  $entries  each entry's weekdays
     * @return list<int> sorted, each once
     */
    public static function days(array $entries): array
    {
        $days = array_values(array_unique(array_merge([], ...$entries)));
        sort($days);

        return $days;
    }
}
