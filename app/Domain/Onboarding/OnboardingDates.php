<?php

namespace App\Domain\Onboarding;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Setup counts in calendar days. Every day it works with is midnight in UTC, read from the calendar date the caller's
 * date shows, so a time of day or a UTC offset never moves it. It builds no date of its own and never reads the clock.
 */
final class OnboardingDates
{
    /** The calendar day `$date` shows, at midnight in UTC. */
    public static function day(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'))
            ->setDate((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'))
            ->setTime(0, 0);
    }

    public static function addingDays(DateTimeImmutable $day, int $days): DateTimeImmutable
    {
        return self::day($day)->modify(($days < 0 ? '' : '+')."{$days} days");
    }

    /** ONB-008, ONB-020: the default start, and the earliest a plan starts. */
    public static function tomorrow(DateTimeImmutable $today): DateTimeImmutable
    {
        return self::addingDays($today, 1);
    }

    /** Whole calendar days from one day to another. */
    public static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return intdiv(self::day($to)->getTimestamp() - self::day($from)->getTimestamp(), 86400);
    }
}
