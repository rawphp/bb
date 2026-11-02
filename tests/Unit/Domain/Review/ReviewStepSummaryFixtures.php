<?php

namespace Tests\Unit\Domain\Review;

use App\Domain\Model\WeighIn;
use App\Domain\Weight\TrendWeight;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Unit\Domain\Plan\PlanFixtures;

/** The helpers of rp-clone's ReviewStepSummaryTests. Week 0 of the fixture grid runs Monday 5 to Sunday 11 January 2026. */
final class ReviewStepSummaryFixtures
{
    public static function day(int $day): DateTimeImmutable
    {
        return PlanFixtures::date(2026, 1, $day);
    }

    public static function weighIn(int $day, int $hour = 7, float $kg = 82): WeighIn
    {
        return new WeighIn("d{$day}h{$hour}", self::day($day)->setTime($hour, 0), $kg);
    }

    /**
     * A weigh-in every day from 20 days before the phase (5 January) to the 18th, so the trend has settled when the
     * phase starts. `$kgOnDay` takes the day of January, which is below 1 for December.
     *
     * @param  Closure(int): float  $kgOnDay
     */
    public static function trend(Closure $kgOnDay): TrendWeight
    {
        $weighIns = [];
        foreach (range(-15, 18) as $number) {
            $weighIns[] = new WeighIn(
                "d{$number}", self::day(5)->modify(($number - 5).' days')->setTime(7, 0), $kgOnDay($number),
            );
        }

        return new TrendWeight($weighIns, new DateTimeZone('UTC'));
    }
}
