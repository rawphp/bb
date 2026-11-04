<?php

use App\Domain\Model\WeighIn;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\WeighIns\WeighInWeeks;
use App\Domain\Weight\TrendWeight;

function weighInWeekDay(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

/** @return array{0: string, 1: string} */
function weighInWeekOf(string $date, ?PlanWeekGrid $grid = null): array
{
    [$start, $end] = WeighInWeeks::of(weighInWeekDay($date), $grid);

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/** A plan from `$start`, its weeks from the review day; PlanWeekGrid counts weekdays from 1 = Sunday. */
function weighInWeekGrid(string $start, int $reviewWeekday): PlanWeekGrid
{
    return new PlanWeekGrid(weighInWeekDay($start), $reviewWeekday);
}

it('WT-008 without a plan a week runs Monday to Sunday', function () {
    expect(weighInWeekOf('2026-10-10'))->toBe(['2026-10-05', '2026-10-11'])
        ->and(weighInWeekOf('2026-10-05'))->toBe(['2026-10-05', '2026-10-11'])
        ->and(weighInWeekOf('2026-10-11'))->toBe(['2026-10-05', '2026-10-11'])
        ->and(weighInWeekOf('2026-10-04'))->toBe(['2026-09-28', '2026-10-04']);
});

it('WT-008 in a plan a week is the plan week, from the review day', function () {
    $grid = weighInWeekGrid('2026-10-24', 7);

    expect(weighInWeekOf('2026-11-04', $grid))->toBe(['2026-10-31', '2026-11-06'])
        ->and(weighInWeekOf('2026-10-31', $grid))->toBe(['2026-10-31', '2026-11-06'])
        ->and(weighInWeekOf('2026-10-30', $grid))->toBe(['2026-10-24', '2026-10-30']);
});

it('WT-008 a plan that starts mid-week has its short first week, and the days before it weeks from its review day that end before it', function () {
    // Starts Wednesday 21 October; weeks start on Saturday.
    $grid = weighInWeekGrid('2026-10-21', 7);

    expect(weighInWeekOf('2026-10-22', $grid))->toBe(['2026-10-21', '2026-10-23'])
        ->and(weighInWeekOf('2026-10-24', $grid))->toBe(['2026-10-24', '2026-10-30'])
        ->and(weighInWeekOf('2026-10-20', $grid))->toBe(['2026-10-17', '2026-10-20'])
        ->and(weighInWeekOf('2026-10-16', $grid))->toBe(['2026-10-10', '2026-10-16']);
});

it('WT-008 a plan week lengthened by a review-day change is one week', function () {
    // From 7 November the review day is Tuesday: the cut would leave 7–9 November, so that week runs on to the 16th.
    $grid = weighInWeekGrid('2026-10-24', 7)->changingReviewDay(3, weighInWeekDay('2026-11-01'));

    expect(weighInWeekOf('2026-11-12', $grid))->toBe(['2026-11-07', '2026-11-16'])
        ->and(weighInWeekOf('2026-11-17', $grid))->toBe(['2026-11-17', '2026-11-23']);
});

it('WT-008 a week\'s trend is the trend on its last day, or today in the week under way, and its change is from the day before it starts', function () {
    $trend = new TrendWeight([
        new WeighIn('a', weighInWeekDay('2026-10-04 09:05'), 83.2),
        new WeighIn('b', weighInWeekDay('2026-10-05 07:00'), 82.9),
        new WeighIn('c', weighInWeekDay('2026-10-10 08:35'), 82.5),
    ], new DateTimeZone('UTC'));
    $today = weighInWeekDay('2026-10-10');

    [$kg, $change] = WeighInWeeks::trend($trend, weighInWeekDay('2026-10-05'), weighInWeekDay('2026-10-11'), $today);
    expect($kg)->toBe($trend->kgOnOrBefore($today))
        ->and($change)->toEqualWithDelta($kg - 83.2, 1e-9);

    [$firstKg, $firstChange] = WeighInWeeks::trend($trend, weighInWeekDay('2026-09-28'), weighInWeekDay('2026-10-04'), $today);
    expect($firstKg)->toBe(83.2)
        ->and($firstChange)->toBeNull();

    expect(WeighInWeeks::trend($trend, weighInWeekDay('2026-09-21'), weighInWeekDay('2026-09-27'), $today))->toBe([null, null]);
});
