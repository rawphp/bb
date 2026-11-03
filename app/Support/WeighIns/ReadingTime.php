<?php

namespace App\Support\WeighIns;

use Carbon\CarbonImmutable;

/**
 * When a weigh-in was taken, as the caller gave it: never the server's clock, so a write replayed from the offline
 * outbox keeps the moment the user acted (OFF-030). The input's rules have already refused a time the calendar or
 * the column does not have.
 *
 * The instant is stored in the app's time zone (UTC). A reading's day is the user's local day, through
 * UserDay::dayOf() (SET-012): the day today_show calls today, the day its write invalidates, and the day the missed-day
 * scan counts it on. So 06:58 on a Saturday at +10:00 is Saturday's weigh-in for a user in that zone.
 */
final class ReadingTime
{
    /** "2026-10-10T08:35:00+01:00" as stored: the same instant in the app's time zone, "2026-10-10 07:35:00". */
    public static function instant(string $time): string
    {
        return CarbonImmutable::parse($time)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
