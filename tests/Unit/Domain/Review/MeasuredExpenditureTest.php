<?php

use App\Domain\Defaults\TBL051ReviewThresholds;
use App\Domain\Review\MeasuredExpenditure;
use Tests\Unit\Domain\Review\CalorieFixtures as F;

// The same tests as rp-clone's MeasuredExpenditureTests (pin 2aa1edeb), one for one.

// REV-021

it('REV-021 week expenditure is intake minus trend change in kcal', function () {
    $trend = F::rampTrend(-0.1, 20);
    $week = F::week(1, intake: 2400);
    $change = F::trendChange($trend, 1);
    expect($change)->toBeLessThan(0);

    $kcal = MeasuredExpenditure::weekKcal($week, $trend);
    expect($kcal)->toEqualWithDelta(2400 - $change * 7700 / 7, 1e-6)
        ->and($kcal)->toBeGreaterThan(2400, 'losing weight means expenditure above intake');
});

it('REV-021 flat trend means expenditure equals intake', function () {
    $kcal = MeasuredExpenditure::weekKcal(F::week(1, intake: 2650), F::flatTrend(20));
    expect($kcal)->toEqualWithDelta(2650, 1e-9);
});

it('REV-021 intake is the average of logged days only', function () {
    // 5 days logged at 2,000 and nothing for the other 2: planned meals never checked in do not count.
    $week = F::week(1, logged: 5, intake: 2000);
    expect($week->averageIntakeKcal())->toEqualWithDelta(2000, 1e-9);
    $kcal = MeasuredExpenditure::weekKcal($week, F::flatTrend(20));
    expect($kcal)->toEqualWithDelta(2000, 1e-9);
});

it('REV-021 week with nothing logged has no expenditure', function () {
    $empty = F::week(1, logged: 0);
    expect($empty->averageIntakeKcal())->toBeNull()
        ->and(MeasuredExpenditure::weekKcal($empty, F::flatTrend(20)))->toBeNull();
});

it('REV-021 week without a trend point has no expenditure', function () {
    // Trend only reaches day 10: week 1 ends on day 13.
    expect(MeasuredExpenditure::weekKcal(F::week(1), F::flatTrend(10)))->toBeNull();
});

it('REV-021 estimate needs three eligible weeks', function () {
    $trend = F::flatTrend(40);
    $weeks = [F::week(0), F::week(1)];
    expect(MeasuredExpenditure::estimate($weeks, $trend))->toBeNull()
        ->and(MeasuredExpenditure::eligibleWeeksNeeded())->toBe(3)
        ->and(MeasuredExpenditure::estimate([...$weeks, F::week(2)], $trend))->not->toBeNull();
});

it('REV-021 estimate weights the last three weeks newest first', function () {
    // Flat trend, so each week's expenditure is its intake. Week 0 is older than the last three.
    $weeks = [
        F::week(0, intake: 1000), F::week(1, intake: 2200), F::week(2, intake: 2400), F::week(3, intake: 2600),
    ];
    $estimate = MeasuredExpenditure::estimate($weeks, F::flatTrend(40));
    expect($estimate)->toEqualWithDelta(0.5 * 2600 + 0.3 * 2400 + 0.2 * 2200, 1e-9);
});

it('REV-021 estimate uses the trend change of each week', function () {
    $trend = F::rampTrend(-0.05, 40);
    $weeks = array_map(fn (int $index) => F::week($index, intake: 2300), range(0, 2));
    $expected = 0.0;
    foreach (range(0, 2) as $index) {
        $weight = [0.2, 0.3, 0.5][$index]; // oldest first
        $expected += $weight * (2300 - F::trendChange($trend, $index) * 7700 / 7);
    }
    expect(MeasuredExpenditure::estimate($weeks, $trend))->toEqualWithDelta($expected, 1e-6);
});

// TBL-051

it('TBL-051 expenditure weights are half three tenths and a fifth', function () {
    expect(TBL051ReviewThresholds::EXPENDITURE_WEEK_WEIGHTS_NEWEST_FIRST)->toBe([0.5, 0.3, 0.2])
        ->and(MeasuredExpenditure::eligibleWeeksNeeded())->toBe(count(TBL051ReviewThresholds::EXPENDITURE_WEEK_WEIGHTS_NEWEST_FIRST));
    // The newest week alone moves the estimate by half of its change.
    $trend = F::flatTrend(40);
    $low = [F::week(0, intake: 2000), F::week(1, intake: 2000), F::week(2, intake: 2000)];
    $high = [F::week(0, intake: 2000), F::week(1, intake: 2000), F::week(2, intake: 3000)];
    $a = MeasuredExpenditure::estimate($low, $trend);
    $b = MeasuredExpenditure::estimate($high, $trend);
    expect($b - $a)->toEqualWithDelta(500, 1e-9);
});

// REV-022

it('REV-022 five logged days is enough and four is not', function () {
    expect(MeasuredExpenditure::MINIMUM_LOGGED_DAYS)->toBe(5)
        ->and(MeasuredExpenditure::isEligible(F::week(1, logged: 5), F::week(0)))->toBeTrue()
        ->and(MeasuredExpenditure::isEligible(F::week(1, logged: 4), F::week(0)))->toBeFalse();
});

it('REV-022 diet break deload and away weeks are not eligible', function () {
    expect(MeasuredExpenditure::isEligible(F::week(1, dietBreak: true), F::week(0)))->toBeFalse()
        ->and(MeasuredExpenditure::isEligible(F::week(1, deload: true), F::week(0)))->toBeFalse()
        ->and(MeasuredExpenditure::isEligible(F::week(1, away: true), F::week(0)))->toBeFalse();
});

it('REV-022 the week straight after one is not eligible', function () {
    expect(MeasuredExpenditure::isEligible(F::week(1), F::week(0, dietBreak: true)))->toBeFalse()
        ->and(MeasuredExpenditure::isEligible(F::week(1), F::week(0, deload: true)))->toBeFalse()
        ->and(MeasuredExpenditure::isEligible(F::week(1), F::week(0, away: true)))->toBeFalse()
        ->and(MeasuredExpenditure::isEligible(F::week(2), F::week(1)))->toBeTrue('two weeks after is fine');
});

it('REV-022 the first ever week has no week before it', function () {
    expect(MeasuredExpenditure::isEligible(F::week(0), null))->toBeTrue();
});

it('REV-022 ineligible weeks are left out of the estimate', function () {
    // Weeks 0-2 eligible at 2,000 / 2,200 / 2,400; week 3 is a diet break and week 4 follows it.
    $trend = F::flatTrend(60);
    $base = [F::week(0, intake: 2000), F::week(1, intake: 2200), F::week(2, intake: 2400)];
    $withBreak = [...$base, F::week(3, intake: 9000, dietBreak: true), F::week(4, intake: 9000)];
    expect(MeasuredExpenditure::estimate($withBreak, $trend))
        ->toEqualWithDelta(MeasuredExpenditure::estimate($base, $trend), 1e-9, 'an ineligible newest week keeps the last estimate');
});

it('REV-022 a poorly logged newest week keeps the last estimate', function () {
    $trend = F::flatTrend(60);
    $base = [F::week(0, intake: 2000), F::week(1, intake: 2200), F::week(2, intake: 2400)];
    $weeks = [...$base, F::week(3, logged: 3, intake: 5000)];
    expect(MeasuredExpenditure::estimate($weeks, $trend))
        ->toEqualWithDelta(0.5 * 2400 + 0.3 * 2200 + 0.2 * 2000, 1e-9);
});

it('REV-022 not enough eligible weeks after a break gives no estimate', function () {
    $weeks = [F::week(0), F::week(1, dietBreak: true), F::week(2), F::week(3), F::week(4)];
    // Eligible: 0, 3, 4 only (2 follows the break) - exactly three.
    expect(MeasuredExpenditure::estimate($weeks, F::flatTrend(60)))->not->toBeNull()
        ->and(MeasuredExpenditure::estimate(array_slice($weeks, 1), F::flatTrend(60)))->toBeNull();
});
