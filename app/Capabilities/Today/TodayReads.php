<?php

namespace App\Capabilities\Today;

use App\Capabilities\Support\ReadKey;
use Illuminate\Support\Carbon;

/**
 * The read keys a Today write invalidates (CAP-010, CAP-017): each changed day's Today and the sheets that read it.
 * today_show and week_show are named even before they register, so the client drops them as soon as it caches one.
 * A day that is today also drops the read without a date, which shows today.
 *
 * A sheet is named by its key as first opened, with only the date. The reads it makes as the user fills it in carry
 * their other inputs, which no write can name; the client must not serve those from its cache (#98).
 */
final class TodayReads
{
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
            if ($date === Carbon::now()->format('Y-m-d')) {
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
        if (in_array(Carbon::now()->format('Y-m-d'), $weekDates, true)) {
            $keys[] = ReadKey::for('week_show');
        }

        return $keys;
    }
}
