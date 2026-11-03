<?php

namespace App\Support;

use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * SET-012: the only place a real instant becomes the user's day or clock time.
 *
 * The app's convention stays: a calendar day is midnight UTC of its Y-m-d, and a wall-clock time is read as UTC, as
 * ClockTime and the plan's meal and session times are. So the user's local time is returned labelled UTC, and every
 * comparison against those days and times works unchanged. The clock is CarbonImmutable's, so the time-walk clock
 * (SET-011) applies.
 */
final class UserDay
{
    public static function zone(User $user): DateTimeZone
    {
        return new DateTimeZone($user->time_zone);
    }

    /** The user's wall-clock time now, labelled UTC. */
    public static function now(User $user): DateTimeImmutable
    {
        return self::clockOf($user, CarbonImmutable::now());
    }

    /** The user's wall-clock time at a stored instant, labelled UTC. A string without an offset is UTC. */
    public static function clockOf(User $user, DateTimeInterface|string $instant): DateTimeImmutable
    {
        return self::clockIn(self::zone($user), $instant);
    }

    /** {@see clockOf()} for a zone already read, such as {@see TrainSnapshot::$zone}. */
    public static function clockIn(DateTimeZone $zone, DateTimeInterface|string $instant): DateTimeImmutable
    {
        $at = is_string($instant)
            ? new DateTimeImmutable($instant, new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($instant);

        return new DateTimeImmutable($at->setTimezone($zone)->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    /** The user's calendar day now, as midnight UTC. */
    public static function today(User $user): DateTimeImmutable
    {
        return TrainSnapshot::day(self::now($user));
    }

    /** The user's calendar day a stored instant falls on, as midnight UTC. A string without an offset is UTC. */
    public static function dayOf(User $user, DateTimeInterface|string $instant): DateTimeImmutable
    {
        return TrainSnapshot::day(self::clockOf($user, $instant));
    }

    /**
     * The first and last second of the user's calendar day `$day` (read from its Y-m-d), as UTC instants. A day the
     * clocks change on is 23 or 25 hours long.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function bounds(User $user, DateTimeImmutable $day): array
    {
        $zone = self::zone($user);
        $utc = new DateTimeZone('UTC');
        $date = $day->format('Y-m-d');
        $first = new DateTimeImmutable($date, $zone);
        $next = new DateTimeImmutable((new DateTimeImmutable($date, $utc))->modify('+1 day')->format('Y-m-d'), $zone);

        return [$first->setTimezone($utc), $next->setTimezone($utc)->modify('-1 second')];
    }
}
