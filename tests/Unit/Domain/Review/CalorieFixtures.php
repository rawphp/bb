<?php

namespace Tests\Unit\Domain\Review;

use App\Domain\Model\PlanWeek;
use App\Domain\Model\WeighIn;
use App\Domain\Phases\StartingCalories;
use App\Domain\Phases\StartingCaloriesSource;
use App\Domain\Review\ReviewWeek;
use App\Domain\Weight\TrendWeight;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared inputs for the calorie review tests (rp-clone's CalorieFixtures): UTC, plan weeks that start on
 * day(7 * index), and a weigh-in per day so the trend is known.
 */
final class CalorieFixtures
{
    public static function zone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /** Midnight UTC, so every day offset lands on a day boundary. */
    public static function origin(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@1799971200'))->setTimezone(self::zone());
    }

    public static function day(int $offset): DateTimeImmutable
    {
        return self::origin()->modify("{$offset} days");
    }

    public static function planWeek(int $index, bool $deload = false, bool $dietBreak = false, bool $away = false): PlanWeek
    {
        return new PlanWeek(start: self::day(7 * $index), isDeload: $deload, isDietBreak: $dietBreak, isAway: $away);
    }

    public static function week(
        int $index,
        int $logged = 7,
        float $intake = 2500,
        float $target = 2500,
        bool $deload = false,
        bool $dietBreak = false,
        bool $away = false,
        bool $higherCarbShift = false,
    ): ReviewWeek {
        return new ReviewWeek(
            week: self::planWeek($index, $deload, $dietBreak, $away),
            loggedIntakeKcal: array_fill(0, $logged, $intake),
            targetKcal: $target,
            hadHigherCarbDayShift: $higherCarbShift,
        );
    }

    /** The same week with a mark changed (the Swift tests mutate the week in place). */
    public static function marked(ReviewWeek $week, ?bool $deload = null, ?bool $away = null, ?bool $higherCarbShift = null): ReviewWeek
    {
        return new ReviewWeek(
            week: new PlanWeek(
                start: $week->week->start,
                dayCount: $week->week->dayCount,
                isDeload: $deload ?? $week->week->isDeload,
                isDietBreak: $week->week->isDietBreak,
                isAway: $away ?? $week->week->isAway,
            ),
            loggedIntakeKcal: $week->loggedIntakeKcal,
            targetKcal: $week->targetKcal,
            hadHigherCarbDayShift: $higherCarbShift ?? $week->hadHigherCarbDayShift,
        );
    }

    /**
     * One weigh-in per day from day -1 (so week 0 has a reading the day before it) to $lastDay; $kg gives the
     * reading for each day.
     *
     * @param  Closure(int): float  $kg
     * @return list<WeighIn>
     */
    public static function weighIns(int $lastDay, Closure $kg): array
    {
        return array_map(
            fn (int $offset) => new WeighIn("w{$offset}", self::day($offset), $kg($offset)),
            range(-1, $lastDay),
        );
    }

    /** @param  list<WeighIn>  $weighIns */
    public static function trend(array $weighIns): TrendWeight
    {
        return new TrendWeight($weighIns, self::zone());
    }

    /** A trend that never moves. */
    public static function flatTrend(int $through, float $kg = 80): TrendWeight
    {
        return self::trend(self::weighIns($through, fn (int $offset) => $kg));
    }

    /** A trend whose readings change by $kgPerDay every day. */
    public static function rampTrend(float $kgPerDay, int $through, float $startKg = 80): TrendWeight
    {
        return self::trend(self::weighIns($through, fn (int $offset) => $startKg + $kgPerDay * ($offset + 1)));
    }

    /** The trend change over a plan week, read back from the trend itself. */
    public static function trendChange(TrendWeight $trend, int $weekIndex): float
    {
        $end = $trend->trendKg(self::day(7 * $weekIndex + 6));
        $before = $trend->trendKg(self::day(7 * $weekIndex - 1));

        return $end - $before;
    }

    /**
     * $count weeks at one target, each logged at exactly its target. With $measurable false the older weeks are too
     * thinly logged (REV-022) for a measured expenditure, so only the newest two are eligible and the formula is used.
     *
     * @return list<ReviewWeek>
     */
    public static function weeks(int $count, float $kcal = 2500, bool $measurable = true): array
    {
        return array_map(
            fn (int $index) => self::week($index, $measurable || $index >= $count - 2 ? 7 : 3, $kcal, $kcal),
            range(0, $count - 1),
        );
    }

    /** kcal per day for a weekly rate (percent of bodyweight) at a trend weight (NUT-007). */
    public static function kcal(float $ratePercent, float $trendKg): float
    {
        return $ratePercent / 100 * $trendKg * 7700 / 7;
    }

    /**
     * A trend whose rate in plan week i is exactly $rates[i] (percent of the trend the day before the week), the
     * last rate carrying on. Readings are worked back from the trend (WT-010), so the trend follows the rates
     * exactly. $readingFactors then scale single days' readings, for spikes.
     *
     * @param  list<float>  $rates
     * @param  array<int, float>  $readingFactors
     */
    public static function shapedTrend(array $rates, int $through, float $startKg = 80, array $readingFactors = []): TrendWeight
    {
        $readings = [new WeighIn('w-1', self::day(-1), $startKg)];
        $trendKg = $startKg;
        $weekStartKg = $startKg;
        for ($offset = 0; $offset <= $through; $offset++) {
            if ($offset % 7 === 0) {
                $weekStartKg = $trendKg;
            }
            $rate = $rates[min(intdiv($offset, 7), count($rates) - 1)];
            $step = $weekStartKg * $rate / 100 / 7;
            $reading = $trendKg + $step / TrendWeight::SMOOTHING_FACTOR;
            $trendKg += $step;
            $readings[] = new WeighIn("w{$offset}", self::day($offset), $reading * ($readingFactors[$offset] ?? 1.0));
        }

        return self::trend($readings);
    }

    public static function isMeasured(StartingCalories $expenditure): bool
    {
        return $expenditure->source === StartingCaloriesSource::Measured;
    }
}
