<?php

namespace App\Support\WeighIns;

use Carbon\CarbonImmutable;

/**
 * When a weigh-in was taken, as the caller gave it: never the server's clock, so a write replayed from the offline
 * outbox keeps the moment the user acted (OFF-030). The input's rules have already refused a time the calendar or
 * the column does not have.
 *
 * Stored in the app's time zone (UTC), a reading belongs to its UTC day: the day today_show calls today, the day its
 * write invalidates, and the day the missed-day scan counts it on. The app has no per-user time zone, so 06:58 on a
 * Saturday at +10:00 is Friday's weigh-in, as Friday is still Today's today for that user.
 */
final class ReadingTime
{
    /** "2026-10-10T08:35:00+01:00" as stored: the same instant in the app's time zone, "2026-10-10 07:35:00". */
    public static function instant(string $time): string
    {
        return CarbonImmutable::parse($time)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
