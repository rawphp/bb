<?php

namespace App\Domain\Progress;

use App\Domain\Model\Phase;
use DateTimeImmutable;
use DateTimeZone;

/** Which days a phase covers: from its start up to, not including, its end. */
final class PhaseDays
{
    public static function contains(Phase $phase, DateTimeImmutable $day, DateTimeZone $zone): bool
    {
        $day = Days::startOf($day, $zone);
        if ($day < Days::startOf($phase->start, $zone)) {
            return false;
        }

        return $phase->end === null || $day < Days::startOf($phase->end, $zone);
    }

    /**
     * The phase that is running on $day, null before the first or after the last.
     *
     * @param  list<Phase>  $phases
     */
    public static function current(array $phases, DateTimeImmutable $day, DateTimeZone $zone): ?Phase
    {
        foreach ($phases as $phase) {
            if (self::contains($phase, $day, $zone)) {
                return $phase;
            }
        }

        return null;
    }
}
