<?php

namespace App\Domain\Plan;

use DateTimeImmutable;

/** How long a mesocycle runs (MESO-001, MESO-043). Days are calendar days in the zone of `$start`. */
final class MesocycleSpan
{
    /**
     * The last day it runs: the end of its `$weeks`, or the day before it was ended early if that is sooner.
     * Sessions logged on later days stay; nothing unstarted is planned after it.
     */
    public static function lastDay(DateTimeImmutable $start, int $weeks, ?DateTimeImmutable $endedOn): DateTimeImmutable
    {
        $first = $start->setTime(0, 0);
        $lastOfWeeks = $first->modify(sprintf('%+d days', $weeks * 7 - 1));
        if ($endedOn === null) {
            return $lastOfWeeks;
        }
        $ended = $endedOn->setTimezone($first->getTimezone());
        $dayBeforeEnd = $first
            ->setDate((int) $ended->format('Y'), (int) $ended->format('n'), (int) $ended->format('j'))
            ->modify('-1 day');

        return min($lastOfWeeks, $dayBeforeEnd);
    }
}
