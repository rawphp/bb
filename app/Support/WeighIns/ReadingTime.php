<?php

namespace App\Support\WeighIns;

use Carbon\CarbonImmutable;
use DomainException;
use Throwable;

/**
 * When a weigh-in was taken or which day steps were counted, as the caller gave it: never the server's clock, so a
 * write replayed from the offline outbox keeps the moment the user acted (OFF-030).
 *
 * The input's date-time format check is a pattern, so a date the calendar does not have passes it; parsing would
 * roll it over to another day. Such a value, and one the weigh_ins timestamp column cannot hold, is refused (422).
 */
final class ReadingTime
{
    public const BAD_TIME = 'Enter a real date and time.';

    public const BAD_DAY = 'Enter a real date.';

    /** The span a MySQL TIMESTAMP holds, a day inside each end. */
    private const FIRST = '1970-01-02 00:00:00';

    private const LAST = '2038-01-18 00:00:00';

    /** "2026-10-10T08:35:00+01:00" as stored: the same instant in the app's time zone, "2026-10-10 07:35:00". */
    public static function instant(string $time): string
    {
        try {
            $at = CarbonImmutable::parse($time);
        } catch (Throwable) {
            throw new DomainException(self::BAD_TIME);
        }
        // 30 February parses as 2 March: what comes back must be what was sent.
        if ($at->format('Y-m-d\TH:i') !== substr($time, 0, 16)) {
            throw new DomainException(self::BAD_TIME);
        }

        $stored = $at->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        if ($stored < self::FIRST || $stored > self::LAST) {
            throw new DomainException(self::BAD_TIME);
        }

        return $stored;
    }

    /** "2026-10-09", refused when the calendar has no such day. */
    public static function day(string $date): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === null || $day->format('Y-m-d') !== $date) {
            throw new DomainException(self::BAD_DAY);
        }

        return $date;
    }
}
