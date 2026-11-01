<?php

namespace App\Domain\Weight;

use App\Domain\Model\WeighIn;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Trend weight from raw weigh-ins (WT-010 to WT-012). Every "current weight" uses
 * currentKg(), never a single reading (WT-011, REV-020). Days are calendar days in $timeZone.
 */
final readonly class TrendWeight
{
    /** WT-010: smoothing factor per day. */
    public const SMOOTHING_FACTOR = 0.1;

    /** WT-012: progress charts need this many weigh-ins. */
    public const MINIMUM_WEIGH_INS_FOR_CHART = 4;

    /** @var list<TrendPoint> */
    public array $points;

    /** @param list<WeighIn> $weighIns */
    public function __construct(array $weighIns, private DateTimeZone $timeZone)
    {
        /** @var array<int, WeighIn> $earliestPerDay keyed by the day's start timestamp */
        $earliestPerDay = [];
        foreach ($weighIns as $weighIn) {
            $day = $this->startOfDay($weighIn->date)->getTimestamp();
            $kept = $earliestPerDay[$day] ?? null;
            if ($kept !== null && $kept->date <= $weighIn->date) {
                continue;
            }
            $earliestPerDay[$day] = $weighIn;
        }
        ksort($earliestPerDay);

        $points = [];
        if ($earliestPerDay !== []) {
            $first = reset($earliestPerDay);
            $day = $this->startOfDay($first->date);
            $lastDay = array_key_last($earliestPerDay);
            $weightKg = $first->weightKg;
            $trendKg = $weightKg;
            while ($day->getTimestamp() <= $lastDay) {
                $reading = $earliestPerDay[$day->getTimestamp()] ?? null;
                if ($reading !== null) {
                    $weightKg = $reading->weightKg;
                }
                if ($points !== []) {
                    $trendKg += self::SMOOTHING_FACTOR * ($weightKg - $trendKg);
                }
                $points[] = new TrendPoint($day, $weightKg, $trendKg, $reading !== null);
                // Wall-clock arithmetic, so a DST change at midnight still lands on the next day.
                $day = $this->startOfDay($day->modify('+1 day'));
            }
        }
        $this->points = $points;
    }

    /** The latest trend weight (WT-011). */
    public function currentKg(): ?float
    {
        return $this->points === [] ? null : $this->points[array_key_last($this->points)]->trendKg;
    }

    /** WT-012: false means the chart shows "not enough data". */
    public function hasEnoughDataForChart(): bool
    {
        $weighIns = array_filter($this->points, fn (TrendPoint $point) => $point->isWeighIn);

        return count($weighIns) >= self::MINIMUM_WEIGH_INS_FOR_CHART;
    }

    public function trendKg(DateTimeImmutable $date): ?float
    {
        $day = $this->startOfDay($date);
        foreach ($this->points as $point) {
            if ($point->day == $day) {
                return $point->trendKg;
            }
        }

        return null;
    }

    /**
     * Signed change in trend over the 7 days ending on $date, in percent of the trend
     * 7 days earlier: positive gains, negative loses (same as Phase). Without a date,
     * the 7 days ending on the last day.
     */
    public function weeklyRatePercent(?DateTimeImmutable $endingOn = null): ?float
    {
        if ($endingOn === null) {
            if ($this->points === []) {
                return null;
            }
            $endingOn = $this->points[array_key_last($this->points)]->day;
        }

        $end = $this->trendKg($endingOn);
        $start = $this->trendKg($endingOn->setTimezone($this->timeZone)->modify('-7 days'));
        if ($end === null || $start === null) {
            return null;
        }

        return ($end - $start) / $start * 100;
    }

    private function startOfDay(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone($this->timeZone)->setTime(0, 0);
    }
}
