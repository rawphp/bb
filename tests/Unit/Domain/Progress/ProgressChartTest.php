<?php

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\WeightUnit;
use App\Domain\Progress\ChartGoalPoint;
use App\Domain\Progress\ProgressChartData;
use App\Domain\Progress\ProgressScope;
use App\Domain\Progress\ProgressShare;
use App\Domain\Progress\ProgressSummary;
use App\Domain\Weight\TrendWeight;
use Tests\Unit\Domain\Progress\ProgressFixtures as F;

/** @return list<Phase> */
function chartPhases(): array
{
    return [
        F::phase('hold', PhaseType::Maintenance, '2026-08-04', '2026-09-01', 0),
        F::phase('cut', PhaseType::FatLoss, '2026-09-01', '2026-10-27', -0.5),
    ];
}

/** 80 kg from 20 August, then 78 kg from 11 September through today. */
function chartTrend(): TrendWeight
{
    return F::trend([...F::steady('2026-08-20', 22, 80), ...F::steady('2026-09-11', 30, 78)]);
}

/** @param  list<Phase>|null  $phases */
function progressChart(ProgressScope $scope, ?TrendWeight $trend = null, ?array $phases = null): ProgressChartData
{
    return ProgressChartData::make(
        $scope, $trend ?? chartTrend(), $phases ?? chartPhases(), F::day('2026-08-04'), F::day('2026-10-10'), F::zone(),
    );
}

/** @param  list<Phase>|null  $phases */
function progressSummary(ProgressScope $scope, ?TrendWeight $trend = null, ?array $phases = null): ?ProgressSummary
{
    return ProgressSummary::make(
        $scope, $trend ?? chartTrend(), $phases ?? chartPhases(), F::day('2026-08-04'), F::day('2026-10-10'), F::zone(),
    );
}

// ANA-001 chart

it('ANA-001 current phase scope runs from the phase start through today', function () {
    $data = progressChart(ProgressScope::CurrentPhase);
    expect($data->trend[0]->day)->toEqual(F::day('2026-09-01'))
        ->and(array_last($data->trend)->day)->toEqual(F::day('2026-10-10'))
        ->and($data->trend)->toHaveCount(40)
        ->and($data->weights)->toHaveCount(40)
        ->and(array_values(array_unique(array_map(fn (ChartGoalPoint $p) => $p->phaseId, $data->goal))))->toBe(['cut'])
        ->and($data->goal)->toHaveCount(40)
        ->and($data->goal[0]->goalKg)->toEqualWithDelta(80, 1e-9);
});

it('ANA-001 whole plan scope keeps each phase\'s own range on its days', function () {
    $data = progressChart(ProgressScope::WholePlan);
    $hold = array_values(array_filter($data->goal, fn (ChartGoalPoint $p) => $p->phaseId === 'hold'));
    $cut = array_values(array_filter($data->goal, fn (ChartGoalPoint $p) => $p->phaseId === 'cut'));
    expect($data->trend[0]->day)->toEqual(F::day('2026-08-20'))
        ->and($data->trend)->toHaveCount(52)
        ->and($hold)->toHaveCount(12)
        ->and($cut)->toHaveCount(40)
        ->and($hold[0]->day)->toEqual(F::day('2026-08-20'))
        ->and(array_last($hold)->day)->toEqual(F::day('2026-08-31'))
        // Maintenance has its corridor, the cut its widening cone.
        ->and(array_last($hold)->upperKg - array_last($hold)->lowerKg)->toEqualWithDelta(80 * 0.02, 1e-9)
        ->and($cut[0]->upperKg - $cut[0]->lowerKg)->toEqualWithDelta(0, 1e-9);
});

it('ANA-001 nothing after today is drawn', function () {
    $data = progressChart(ProgressScope::CurrentPhase, F::trend(F::steady('2026-09-01', 50, 80)));
    expect(array_last($data->trend)->day)->toEqual(F::day('2026-10-10'))
        ->and(array_last($data->weights)->day)->toEqual(F::day('2026-10-10'));
});

it('ANA-001 daily weights are only the days with a weigh-in', function () {
    $everyOtherDay = array_map(fn (int $i) => F::weighIn('2026-09-01', 80, $i * 2), range(0, 19));
    $data = progressChart(ProgressScope::CurrentPhase, F::trend($everyOtherDay));
    expect($data->weights)->toHaveCount(20)
        ->and(count($data->trend))->toBeGreaterThan(20);
});

it('ANA-001 without a current phase the chart runs from the plan start', function () {
    $ended = [F::phase('cut', PhaseType::FatLoss, '2026-08-20', '2026-09-10', -0.5)];
    $data = progressChart(ProgressScope::CurrentPhase, phases: $ended);
    expect($data->trend[0]->day)->toEqual(F::day('2026-08-20'))
        ->and(array_last($data->trend)->day)->toEqual(F::day('2026-10-10'))
        // Only the days inside the phase have a range.
        ->and($data->goal)->toHaveCount(21);
});

// ANA-002 summary

it('ANA-002 summary of the current phase', function () {
    $trend = chartTrend();
    $summary = progressSummary(ProgressScope::CurrentPhase, $trend);
    expect($summary)->not->toBeNull()
        ->and($summary->startKg)->toEqualWithDelta(80, 1e-9)
        ->and($summary->currentKg)->toEqualWithDelta($trend->currentKg(), 1e-9)
        ->and($summary->changeKg())->toEqualWithDelta($summary->currentKg - 80, 1e-9)
        ->and($summary->changeKg())->toBeLessThan(0)
        // The line at the phase's end date: 8 weeks at -0.5% from 80 kg.
        ->and($summary->goalKg)->toEqualWithDelta(80 * 0.96, 1e-9)
        ->and($summary->phaseType)->toBe(PhaseType::FatLoss)
        ->and($summary->startDay)->toEqual(F::day('2026-09-01'))
        ->and($summary->currentDay)->toEqual(F::day('2026-10-10'));
});

it('ANA-002 whole plan starts at the first trend point of the plan', function () {
    $summary = progressSummary(ProgressScope::WholePlan);
    expect($summary?->startKg)->toEqualWithDelta(80, 1e-9)
        ->and($summary?->startDay)->toEqual(F::day('2026-08-20'))
        // The goal is still the current phase's.
        ->and($summary?->goalKg)->toEqualWithDelta(80 * 0.96, 1e-9);
});

it('ANA-002 a phase with no end and a rate has no goal weight', function () {
    $open = [F::phase('cut', PhaseType::FatLoss, '2026-09-01', null, -0.5)];
    $summary = progressSummary(ProgressScope::CurrentPhase, phases: $open);
    expect($summary)->not->toBeNull()
        ->and($summary->goalKg)->toBeNull();
});

it('ANA-002 a maintenance with no end holds the start weight', function () {
    $open = [F::phase('hold', PhaseType::Maintenance, '2026-09-01', null, 0)];
    expect(progressSummary(ProgressScope::CurrentPhase, phases: $open)?->goalKg)->toEqualWithDelta(80, 1e-9);
});

it('ANA-002 no summary without trend data', function () {
    expect(progressSummary(ProgressScope::CurrentPhase, F::trend([])))->toBeNull();
});

it('ANA-002 no goal without a current phase', function () {
    $summary = progressSummary(ProgressScope::WholePlan, phases: []);
    expect($summary)->not->toBeNull()
        ->and($summary->goalKg)->toBeNull()
        ->and($summary->phaseType)->toBeNull();
});

// ANA-010 share text

it('ANA-010 share text names the phase, the weights and the weeks', function () {
    $summary = new ProgressSummary(
        startKg: 82, currentKg: 80.4, goalKg: 78, phaseType: PhaseType::FatLoss,
        startDay: F::day('2026-09-01'), currentDay: F::day('2026-09-22'),
    );
    expect(ProgressShare::text($summary, WeightUnit::Kg))->toBe('Fat loss: 82.0 kg to 80.4 kg (-1.6 kg) over 3 weeks')
        ->and(ProgressShare::TITLE)->toBe('My progress');
});

it('ANA-010 share text says one week and signs a gain', function () {
    $summary = new ProgressSummary(
        startKg: 70, currentKg: 70.5, goalKg: null, phaseType: PhaseType::MuscleGain,
        startDay: F::day('2026-09-01'), currentDay: F::day('2026-09-04'),
    );
    expect(ProgressShare::text($summary, WeightUnit::Kg))->toBe('Muscle gain: 70.0 kg to 70.5 kg (+0.5 kg) over 1 week');
});

it('ANA-010 share text without a phase and in pounds', function () {
    $summary = new ProgressSummary(
        startKg: 90.72, currentKg: 90.72, goalKg: null, phaseType: null,
        startDay: F::day('2026-09-01'), currentDay: F::day('2026-09-15'),
    );
    expect(ProgressShare::text($summary, WeightUnit::Lb))->toBe('Progress: 200.0 lb to 200.0 lb (+0.0 lb) over 2 weeks');
});

it('ANA-002 trend points after today are left out of the summary', function () {
    // Weigh-ins run past today (10 October) and change after it: the current weight is today's trend.
    $trend = F::trend([...F::steady('2026-09-01', 40, 80), ...F::steady('2026-10-11', 10, 70)]);
    $summary = progressSummary(ProgressScope::CurrentPhase, $trend);
    expect($summary?->currentDay)->toEqual(F::day('2026-10-10'))
        ->and($summary?->currentKg)->toEqualWithDelta($trend->trendKg(F::day('2026-10-10')), 1e-9)
        ->and($summary?->currentKg)->toEqualWithDelta(80, 1e-9);
});

it('ANA-001 the scope titles', function () {
    expect(ProgressScope::CurrentPhase->title())->toBe('Phase')
        ->and(ProgressScope::WholePlan->title())->toBe('Whole plan');
});

it('PH-001 every phase type has the name a user reads', function () {
    expect(array_map(fn (PhaseType $type) => $type->title(), PhaseType::cases()))
        ->toBe(['Fat loss', 'Mini-cut', 'Maintenance', 'Muscle gain']);
});
