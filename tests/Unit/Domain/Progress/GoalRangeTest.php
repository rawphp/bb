<?php

use App\Domain\Model\PhaseType;
use App\Domain\Progress\GoalRange;
use Tests\Unit\Domain\Progress\ProgressFixtures as F;

it('ANA-001 the goal line follows the target rate from the start trend', function () {
    // 80 kg steady, losing 0.5% a week: after 4 weeks the line is at 80 x (1 - 0.02).
    $trend = F::trend(F::steady('2026-09-01', 40, 80));
    $phase = F::phase('cut', PhaseType::FatLoss, '2026-09-01', '2026-11-10', -0.5);
    $range = GoalRange::make($phase, $trend, F::zone());

    expect($range)->not->toBeNull()
        ->and($range->startTrendKg)->toEqualWithDelta(80, 1e-9)
        ->and($range->anchorDay)->toEqual(F::day('2026-09-01'))
        ->and($range->point(F::day('2026-09-01'))->goalKg)->toEqualWithDelta(80, 1e-9)
        ->and($range->point(F::day('2026-09-29'))->goalKg)->toEqualWithDelta(78.4, 1e-9)
        // Half a week is half the change.
        ->and($range->point(F::day('2026-09-04'))->goalKg)->toEqualWithDelta(80 * (1 - 0.005 * 3 / 7), 1e-9);
});

it('ANA-001 a gain phase rises with a positive rate', function () {
    $trend = F::trend(F::steady('2026-09-01', 40, 70));
    $range = GoalRange::make(F::phase('bulk', PhaseType::MuscleGain, '2026-09-01', null, 0.25), $trend, F::zone());

    expect($range?->point(F::day('2026-09-29'))->goalKg)->toEqualWithDelta(70.7, 1e-9);
});

it('ANA-001 a fat loss band is at least 0.15 percent per week and widens each week', function () {
    // Rate -0.5: 25% of it is 0.125, under the 0.15 floor, so the band is 0.15% of 80 kg = 0.12 kg a week.
    $trend = F::trend(F::steady('2026-09-01', 40, 80));
    $range = GoalRange::make(F::phase('cut', PhaseType::FatLoss, '2026-09-01', '2026-11-10', -0.5), $trend, F::zone());

    $start = $range?->point(F::day('2026-09-01'));
    $fourWeeks = $range?->point(F::day('2026-09-29'));
    expect($start->upperKg - $start->lowerKg)->toEqualWithDelta(0, 1e-9)
        ->and($fourWeeks->upperKg - $fourWeeks->goalKg)->toEqualWithDelta(80 * 0.0015 * 4, 1e-9)
        ->and($fourWeeks->goalKg - $fourWeeks->lowerKg)->toEqualWithDelta(80 * 0.0015 * 4, 1e-9);
});

it('ANA-001 a fast rate uses the relative band', function () {
    // Rate -1.0: 25% of it is 0.25 percent a week, over the 0.15 floor. Two weeks of it on 80 kg is 0.4 kg.
    $trend = F::trend(F::steady('2026-09-01', 40, 80));
    $range = GoalRange::make(F::phase('cut', PhaseType::FatLoss, '2026-09-01', '2026-11-10', -1), $trend, F::zone());

    $point = $range?->point(F::day('2026-09-15'));
    expect($point->goalKg)->toEqualWithDelta(80 * 0.98, 1e-9)
        ->and($point->upperKg - $point->goalKg)->toEqualWithDelta(0.4, 1e-9);
});

it('ANA-001 maintenance uses the one percent corridor', function () {
    // REV-029: plus or minus 1% of the start trend weight, the same width all phase long.
    $trend = F::trend(F::steady('2026-09-01', 40, 80));
    $range = GoalRange::make(F::phase('hold', PhaseType::Maintenance, '2026-09-01', null, 0), $trend, F::zone());

    foreach (['2026-09-01', '2026-09-11', '2026-10-05'] as $text) {
        $point = $range?->point(F::day($text));
        expect($point->goalKg)->toEqualWithDelta(80, 1e-9)
            ->and($point->lowerKg)->toEqualWithDelta(79.2, 1e-9)
            ->and($point->upperKg)->toEqualWithDelta(80.8, 1e-9);
    }
});

it('ANA-001 a maintenance gain target moves the corridor centre', function () {
    // PH-004: a small rate moves the centre; the corridor keeps its width.
    $trend = F::trend(F::steady('2026-09-01', 40, 80));
    $range = GoalRange::make(F::phase('hold', PhaseType::Maintenance, '2026-09-01', null, 0.1), $trend, F::zone());

    $point = $range?->point(F::day('2026-09-15'));
    expect($point->goalKg)->toEqualWithDelta(80 * 1.002, 1e-9)
        ->and($point->upperKg - $point->goalKg)->toEqualWithDelta(0.8, 1e-9);
});

it('ANA-001 no weigh-in on the start day anchors at the first trend point after', function () {
    // The first weigh-in is 3 days into the phase: S is that day's trend and weeks count from it.
    $trend = F::trend(F::steady('2026-09-04', 40, 90));
    $range = GoalRange::make(F::phase('cut', PhaseType::FatLoss, '2026-09-01', null, -0.5), $trend, F::zone());

    expect($range?->anchorDay)->toEqual(F::day('2026-09-04'))
        ->and($range?->startTrendKg)->toEqualWithDelta(90, 1e-9)
        ->and($range?->point(F::day('2026-09-11'))->goalKg)->toEqualWithDelta(90 * 0.995, 1e-9);
});

it('ANA-001 no range without trend data in the phase', function () {
    // All weigh-ins are before the phase.
    expect(GoalRange::make(
        F::phase('cut', PhaseType::FatLoss, '2026-09-01', null, -0.5), F::trend(F::steady('2026-08-01', 10, 80)), F::zone(),
    ))->toBeNull()
        // The first weigh-in after the start is after the phase has ended.
        ->and(GoalRange::make(
            F::phase('old', PhaseType::FatLoss, '2026-07-01', '2026-07-29', -0.5), F::trend(F::steady('2026-08-01', 10, 80)), F::zone(),
        ))->toBeNull()
        // No weigh-ins at all.
        ->and(GoalRange::make(
            F::phase('cut', PhaseType::FatLoss, '2026-09-01', null, -0.5), F::trend([]), F::zone(),
        ))->toBeNull();
});
