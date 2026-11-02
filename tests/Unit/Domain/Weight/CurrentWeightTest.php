<?php

use App\Domain\Model\WeighIn;
use App\Domain\Weight\CurrentWeight;
use App\Domain\Weight\TrendWeight;

function currentWeightDay(int $offset): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-01-01 07:00', new DateTimeZone('UTC')))->modify("+{$offset} days");
}

it('WT-011 the trend is the current weight', function () {
    expect(CurrentWeight::kg(79.5, 82.0))->toBe(79.5)
        ->and(CurrentWeight::kg(79.5, null))->toBe(79.5);
});

it('WT-011 before any weigh-in the profile\'s weight is the current weight', function () {
    expect(CurrentWeight::kg(null, 82.0))->toBe(82.0)
        ->and(CurrentWeight::kg(null, null))->toBeNull();
});

it('WT-011 from weigh-ins the current weight is the smoothed trend, not the latest reading', function () {
    $utc = new DateTimeZone('UTC');
    $weighIns = [
        new WeighIn('a', currentWeightDay(0), 80.0),
        new WeighIn('b', currentWeightDay(1), 81.0),
    ];
    $trend = (new TrendWeight($weighIns, $utc))->currentKg();

    expect($trend)->toEqualWithDelta(80.1, 0.001)
        ->and(CurrentWeight::fromWeighIns($weighIns, 70.0, $utc))->toBe($trend)
        ->and(CurrentWeight::fromWeighIns([], 70.0, $utc))->toBe(70.0)
        ->and(CurrentWeight::fromWeighIns([], null, $utc))->toBeNull();
});
