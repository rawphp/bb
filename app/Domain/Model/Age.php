<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/** Age in whole years (NUT-001, SAFE-001): the one place that counts it. */
final class Age
{
    /**
     * Whole years from $birthDate to $day, each read as the calendar date it is in its own time zone, so neither
     * a time of day nor a UTC offset moves a birthday. A 29 February birthday turns over on 1 March in a common
     * year. It builds no date of its own, so it never reads the clock.
     */
    public static function years(DateTimeImmutable $birthDate, DateTimeImmutable $day): int
    {
        $age = (int) $day->format('Y') - (int) $birthDate->format('Y');

        return $day->format('md') < $birthDate->format('md') ? $age - 1 : $age;
    }
}
