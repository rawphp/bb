<?php

namespace App\Capabilities\Today;

use App\Capabilities\Support\ReadKey;
use Illuminate\Support\Carbon;

/**
 * The read keys a Today write invalidates (CAP-010, CAP-017): each changed day's Today and the sheets that read it.
 * A day that may be today also drops the read without a date, which shows today. Today is the user's local day
 * (SET-012), which in any time zone is the UTC day or one either side of it, so all three count.
 *
 * A sheet is named by its key as first opened, with only the date. The reads it makes as the user fills it in carry
 * their other inputs, which no write can name; the client must not serve those from its cache (#98).
 */
final class TodayReads
{
    /** The sheets that read a day's meals and session, by their date-only key. */
    public const SHEETS = ['day_optimize_show', 'day_redistribute_show', 'day_adjust_show'];

    /** SCH-051, SCH-057: the workout screen of a session whose row moved, re-timed or skipped it. */
    public static function workout(string $mesocycleId, int $week, int $day): string
    {
        return ReadKey::for('workout_show', ['mesocycle_id' => $mesocycleId, 'week' => $week, 'day' => $day]);
    }

    /**
     * A re-target of these days: each one's Today and sheets, and the week strip of their plan week.
     *
     * @param  list<string>  $dates  Y-m-d
     * @param  list<string>  $weekDates  Y-m-d
     * @return list<string>
     */
    public static function forDays(array $dates, array $weekDates): array
    {
        return [...self::keys($dates, self::SHEETS), ...self::weekKeys($weekDates)];
    }

    /**
     * @param  list<string>  $dates  Y-m-d
     * @param  list<string>  $sheets  the sheet reads, such as day_redistribute_show, by their date-only key
     * @return list<string>
     */
    public static function keys(array $dates, array $sheets = []): array
    {
        $keys = [];
        foreach (array_values(array_unique($dates)) as $date) {
            $keys[] = ReadKey::for('today_show', ['date' => $date]);
            if (in_array($date, self::mayBeToday(), true)) {
                $keys[] = ReadKey::for('today_show');
            }
            foreach ($sheets as $sheet) {
                $keys[] = ReadKey::for($sheet, ['date' => $date]);
            }
        }

        return $keys;
    }

    /**
     * NUT-021, SCH-022: the week strip of every day of a plan week whose day totals or sessions changed. The strip is
     * read by the day it shows, so each day of the week is named.
     *
     * @param  list<string>  $weekDates  Y-m-d
     * @return list<string>
     */
    public static function weekKeys(array $weekDates): array
    {
        $keys = array_map(fn (string $date) => ReadKey::for('week_show', ['date' => $date]), $weekDates);
        if (array_intersect(self::mayBeToday(), $weekDates) !== []) {
            $keys[] = ReadKey::for('week_show');
        }

        return $keys;
    }

    /** @return list<string> the days, Y-m-d, that are today in some time zone */
    private static function mayBeToday(): array
    {
        $now = Carbon::now('UTC');

        return [$now->copy()->subDay()->format('Y-m-d'), $now->format('Y-m-d'), $now->copy()->addDay()->format('Y-m-d')];
    }
}
