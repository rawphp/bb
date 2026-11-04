<?php

use App\Domain\Model\WeighIn;
use App\Domain\Weight\TrendWeight;

function trendDay(int $offset, int $hour = 7): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')))
        ->modify("+{$offset} days")
        ->setTime($hour, 0);
}

function trendWeighIn(int $offset, float $kg, int $hour = 7): WeighIn
{
    return new WeighIn("w{$offset}-{$hour}", trendDay($offset, $hour), $kg);
}

/** @param list<WeighIn> $weighIns */
function trendOf(array $weighIns): TrendWeight
{
    return new TrendWeight($weighIns, new DateTimeZone('UTC'));
}

/**
 * 21 days with gaps (days 2, 6, 7, 11, 14, 15, 19) and two readings on day 4,
 * the later one listed first.
 *
 * @return list<WeighIn>
 */
function trendFixture(): array
{
    return [
        trendWeighIn(0, 80.0), trendWeighIn(1, 79.6), trendWeighIn(3, 80.4),
        trendWeighIn(4, 78.9, hour: 18), trendWeighIn(4, 79.8, hour: 7),
        trendWeighIn(5, 79.5), trendWeighIn(8, 79.2), trendWeighIn(9, 79.4), trendWeighIn(10, 78.8),
        trendWeighIn(12, 79.0), trendWeighIn(13, 78.6), trendWeighIn(16, 78.4), trendWeighIn(17, 78.7),
        trendWeighIn(18, 78.2), trendWeighIn(20, 78.0),
    ];
}

it('WT-010 smooths daily weights, holding the last value on missing days', function () {
    // Expected trend for days 0 to 20, worked out by hand from WT-010.
    $expected = [
        80.00, 79.96, 79.92, 79.97, 79.95, 79.91, 79.87, 79.83, 79.77, 79.73, 79.64,
        79.55, 79.50, 79.41, 79.33, 79.26, 79.17, 79.12, 79.03, 78.95, 78.85,
    ];
    $trend = trendOf(trendFixture());

    expect($trend->points)->toHaveCount(21);
    foreach ($expected as $offset => $kg) {
        $point = $trend->points[$offset];
        expect($point->day)->toEqual(trendDay($offset, hour: 0))
            ->and($point->trendKg)->toEqualWithDelta($kg, 0.01)
            ->and($trend->trendKg(trendDay($offset, hour: 22)))->toEqualWithDelta($kg, 0.01);
    }
    expect(TrendWeight::SMOOTHING_FACTOR)->toBe(0.1)
        // Day 2 has no weigh-in, so it holds day 1's weight.
        ->and($trend->points[2]->weightKg)->toBe(79.6)
        ->and($trend->points[2]->isWeighIn)->toBeFalse()
        ->and($trend->points[3]->isWeighIn)->toBeTrue();
});

it('WT-010 the trend starts at the first weigh-in', function () {
    $trend = trendOf([trendWeighIn(3, 72.5)]);

    expect(array_map(fn ($p) => $p->trendKg, $trend->points))->toBe([72.5])
        ->and($trend->trendKg(trendDay(2)))->toBeNull();
});

it('WT-010 no weigh-ins has no trend', function () {
    $trend = trendOf([]);

    expect($trend->points)->toBeEmpty()
        ->and($trend->currentKg())->toBeNull()
        ->and($trend->weeklyRatePercent())->toBeNull();
});

it('WT-010 several weigh-ins on one day use the earliest', function () {
    $trend = trendOf(trendFixture());

    expect($trend->points[4]->weightKg)->toBe(79.8)
        ->and($trend->points[4]->trendKg)->toEqualWithDelta(79.95, 0.01);

    $earliestListedFirst = trendOf([trendWeighIn(0, 79.8, hour: 7), trendWeighIn(0, 78.9, hour: 18)]);

    expect(array_map(fn ($p) => $p->weightKg, $earliestListedFirst->points))->toBe([79.8]);
});

it('WT-010 days stay whole across a midnight daylight saving change', function () {
    // Chile moves clocks forward at midnight on 2026-09-06, so that day starts at 01:00.
    $zone = new DateTimeZone('America/Santiago');
    $reading = fn (int $day, float $kg) => new WeighIn("s{$day}", new DateTimeImmutable("2026-09-{$day} 07:00", $zone), $kg);

    $trend = new TrendWeight([$reading(4, 70.0), $reading(6, 71.0), $reading(8, 72.0), $reading(10, 73.0)], $zone);

    expect($trend->points)->toHaveCount(7)
        ->and(array_map(fn ($p) => $p->isWeighIn, $trend->points))->toBe([true, false, true, false, true, false, true])
        ->and(array_map(fn ($p) => $p->weightKg, $trend->points))->toBe([70.0, 70.0, 71.0, 71.0, 72.0, 72.0, 73.0])
        ->and($trend->points[2]->day)->toEqual(new DateTimeImmutable('2026-09-06 01:00', $zone))
        ->and($trend->points[6]->day)->toEqual(new DateTimeImmutable('2026-09-10 00:00', $zone))
        ->and($trend->trendKg($reading(9, 0)->date))->not->toBeNull();
});

it('WT-010 days are local days in the given zone, not the readings\' zone', function () {
    // Tokyo is UTC+9, so local midnight is 15:00 UTC. The readings are stored in UTC.
    $tokyo = new DateTimeZone('Asia/Tokyo');
    $utc = fn (string $at) => new DateTimeImmutable($at, new DateTimeZone('UTC'));

    $trend = new TrendWeight([
        new WeighIn('a', $utc('2026-03-01 14:00'), 70.0), // 23:00 on 1 March in Tokyo
        new WeighIn('c', $utc('2026-03-02 02:00'), 72.0), // 11:00 on 2 March, listed first
        new WeighIn('b', $utc('2026-03-01 16:00'), 71.0), // 01:00 on 2 March, the day's earliest
        new WeighIn('d', $utc('2026-03-08 16:00'), 73.0), // 01:00 on 9 March
    ], $tokyo);

    expect($trend->points)->toHaveCount(9)
        ->and($trend->points[0]->day)->toEqual(new DateTimeImmutable('2026-03-01 00:00', $tokyo))
        ->and($trend->points[1]->day)->toEqual(new DateTimeImmutable('2026-03-02 00:00', $tokyo))
        ->and($trend->points[8]->day)->toEqual(new DateTimeImmutable('2026-03-09 00:00', $tokyo))
        ->and(array_map(fn ($p) => $p->weightKg, $trend->points))
        ->toBe([70.0, 71.0, 71.0, 71.0, 71.0, 71.0, 71.0, 71.0, 73.0])
        ->and($trend->trendKg($utc('2026-03-01 14:00')))->toBe(70.0)
        ->and($trend->trendKg($utc('2026-03-01 16:00')))->toEqualWithDelta(70.1, 0.0001)
        // 70.1 + 0.9 * 0.9^6 toward 71 by 8 March, then a tenth of the way to 73.
        ->and($trend->currentKg())->toEqualWithDelta(70.7695, 0.0001)
        // (70.7695 - 70.1) / 70.1 * 100, with the end date given in UTC.
        ->and($trend->weeklyRatePercent($utc('2026-03-08 16:00')))->toEqualWithDelta(0.9551, 0.0005);
});

it('WT-011 the weekly rate counts back 7 local days across a daylight saving change', function () {
    // Chile moves clocks forward at midnight on 2026-09-06: UTC-4 before, UTC-3 after.
    $zone = new DateTimeZone('America/Santiago');
    $reading = fn (int $day, float $kg) => new WeighIn("s{$day}", new DateTimeImmutable("2026-09-0{$day} 07:00", $zone), $kg);
    $trend = new TrendWeight([$reading(2, 70.0), $reading(3, 71.0), new WeighIn('s10', new DateTimeImmutable('2026-09-10 07:00', $zone), 72.0)], $zone);

    // 03:30 UTC is 00:30 on 10 September in Santiago, so 7 days back is 3 September,
    // not 2 September as counting back in UTC would give.
    // (70.6695 - 70.1) / 70.1 * 100
    expect($trend->weeklyRatePercent(new DateTimeImmutable('2026-09-10 03:30', new DateTimeZone('UTC'))))
        ->toEqualWithDelta(0.8125, 0.0005);
});

it('WT-011 the current weight is the latest trend, not the last reading', function () {
    $trend = trendOf(trendFixture());

    expect($trend->currentKg())->toEqualWithDelta(78.85, 0.01)
        ->and(abs($trend->currentKg() - 78.0))->toBeGreaterThan(0.1);
});

it('WT-011 the weekly rate is percent of bodyweight per week from the trend', function () {
    $trend = trendOf(trendFixture());

    // (78.8527 - 79.4091) / 79.4091 * 100
    expect($trend->weeklyRatePercent())->toEqualWithDelta(-0.7006, 0.0005)
        // (79.8313 - 80.0) / 80.0 * 100
        ->and($trend->weeklyRatePercent(trendDay(7)))->toEqualWithDelta(-0.2109, 0.0005)
        ->and($trend->weeklyRatePercent(trendDay(6)))->toBeNull();
});

it('WT-012 charts need four weigh-ins', function () {
    $three = [trendWeighIn(0, 80.0), trendWeighIn(1, 79.8), trendWeighIn(3, 79.9)];

    expect(TrendWeight::MINIMUM_WEIGH_INS_FOR_CHART)->toBe(4)
        ->and(trendOf([])->hasEnoughDataForChart())->toBeFalse()
        ->and(trendOf($three)->hasEnoughDataForChart())->toBeFalse()
        ->and(trendOf([...$three, trendWeighIn(5, 79.7)])->hasEnoughDataForChart())->toBeTrue()
        // A second reading on a day already counted is not another weigh-in.
        ->and(trendOf([...$three, trendWeighIn(3, 79.5, hour: 20)])->hasEnoughDataForChart())->toBeFalse()
        ->and(trendOf(trendFixture())->hasEnoughDataForChart())->toBeTrue();
});

it('WT-008 the trend on or before a day holds the last trend after the last weigh-in, and has none before the first', function () {
    $trend = trendOf([trendWeighIn(2, 80.0), trendWeighIn(4, 79.0)]);

    expect($trend->kgOnOrBefore(trendDay(1)))->toBeNull()
        ->and($trend->kgOnOrBefore(trendDay(2)))->toBe(80.0)
        ->and($trend->kgOnOrBefore(trendDay(3, hour: 23)))->toBe(80.0)
        ->and($trend->kgOnOrBefore(trendDay(4)))->toEqualWithDelta(79.9, 1e-9)
        ->and($trend->kgOnOrBefore(trendDay(30)))->toEqualWithDelta(79.9, 1e-9)
        ->and(trendOf([])->kgOnOrBefore(trendDay(4)))->toBeNull();
});
