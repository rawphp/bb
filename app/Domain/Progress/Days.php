<?php

namespace App\Domain\Progress;

use DateTimeImmutable;
use DateTimeZone;

/** Calendar days in the user's time zone: the Swift `Calendar` helpers this folder leans on. */
final class Days
{
    public static function startOf(DateTimeImmutable $date, DateTimeZone $zone): DateTimeImmutable
    {
        return $date->setTimezone($zone)->setTime(0, 0);
    }

    /** Whole days from $start to $end, both taken at the start of their day (negative when $end is earlier). */
    public static function whole(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $zone): int
    {
        return (int) self::startOf($start, $zone)->diff(self::startOf($end, $zone))->format('%r%a');
    }
}
