<?php

namespace App\Capabilities\Today;

use App\Capabilities\Support\ReadKey;
use Illuminate\Support\Carbon;

/**
 * The read keys a Today write invalidates (CAP-010, CAP-017): each changed day's Today and the sheets that read it.
 * today_show is named even before it registers, so the client drops it as soon as it caches one. A day that is today
 * also drops the Today read without a date, which shows today.
 */
final class TodayReads
{
    /**
     * @param  list<string>  $dates  Y-m-d
     * @param  list<string>  $sheets  the sheet reads keyed by date alone, such as day_redistribute_show
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
}
