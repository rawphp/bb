<?php

use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Progress\LoggedWorkingSet;
use App\Domain\Progress\MesocycleHistory;
use App\Domain\Progress\MuscleWeeklySets;
use App\Domain\Progress\PhaseHistory;
use App\Domain\Progress\PhaseHistoryEntry;
use App\Domain\Progress\ProgressMesocycle;
use App\Domain\Weight\TrendWeight;
use Tests\Unit\Domain\Progress\ProgressFixtures as F;

/** 82 kg until 31 August, then 80 kg. */
function historyTrend(): TrendWeight
{
    return F::trend([...F::steady('2026-08-04', 28, 82), ...F::steady('2026-09-01', 40, 80)]);
}

/**
 * @param  list<Phase>  $phases
 * @param  list<ProgressMesocycle>  $mesocycles
 * @return list<PhaseHistoryEntry>
 */
function phaseHistory(array $phases, array $mesocycles = [], ?TrendWeight $trend = null): array
{
    return PhaseHistory::entries($phases, $mesocycles, $trend ?? historyTrend(), F::day('2026-10-10'), F::zone());
}

/** @return list<LoggedWorkingSet> */
function loggedSets(string $meso, int $week, MuscleGroup $muscle, int $count = 1): array
{
    return array_fill(0, $count, new LoggedWorkingSet($meso, $week, $muscle));
}

// ANA-006 phase history

it('ANA-006 lists only phases that ended on or before today', function () {
    $phases = [
        F::phase('a', PhaseType::FatLoss, '2026-08-04', '2026-09-01', -0.5),
        F::phase('b', PhaseType::Maintenance, '2026-09-01', '2026-10-10', 0),
        F::phase('c', PhaseType::MuscleGain, '2026-10-10', '2026-11-07', 0.25),
        F::phase('d', PhaseType::FatLoss, '2026-08-01', null, -0.5),
    ];
    // b ended today (exclusive end), so it is past; c has not started; d has no end.
    expect(array_map(fn (PhaseHistoryEntry $e) => $e->id, phaseHistory($phases)))->toBe(['b', 'a']);
});

it('ANA-006 newest first with dates and type', function () {
    $list = phaseHistory([
        F::phase('old', PhaseType::FatLoss, '2026-08-04', '2026-09-01', -0.5),
        F::phase('new', PhaseType::Maintenance, '2026-09-01', '2026-09-29', 0),
    ]);
    expect(array_map(fn (PhaseHistoryEntry $e) => $e->id, $list))->toBe(['new', 'old'])
        ->and($list[1]->type)->toBe(PhaseType::FatLoss)
        ->and($list[1]->start)->toEqual(F::day('2026-08-04'))
        ->and($list[1]->end)->toEqual(F::day('2026-09-01'));
});

it('ANA-006 weight change is the trend on the last day less the trend at the start', function () {
    $trend = historyTrend();
    $entry = phaseHistory([F::phase('a', PhaseType::FatLoss, '2026-08-04', '2026-09-29', -0.5)], trend: $trend)[0];

    $atStart = $trend->trendKg(F::day('2026-08-04'));
    $lastDay = $trend->trendKg(F::day('2026-09-28'));
    expect($atStart)->toEqualWithDelta(82, 1e-9)
        ->and($entry->weightChangeKg)->toEqualWithDelta($lastDay - $atStart, 1e-9)
        ->and($entry->weightChangeKg)->toBeLessThan(0);
});

it('ANA-006 average rate is percent of start per week', function () {
    // 8 weeks, 82 kg to 80 kg: about -0.305% a week.
    $phase = F::phase('a', PhaseType::FatLoss, '2026-08-04', '2026-09-29', -0.5);
    $flat = phaseHistory([$phase], trend: F::trend(F::steady('2026-08-04', 56, 82)))[0];
    expect($flat->weightChangeKg)->toEqualWithDelta(0, 1e-9)
        ->and($flat->averageRatePercentPerWeek)->toEqualWithDelta(0, 1e-9);

    $entry = phaseHistory([$phase])[0];
    expect($entry->averageRatePercentPerWeek)->toEqualWithDelta($entry->weightChangeKg / 82 * 100 / 8, 1e-9);
});

it('ANA-006 no trend data means no change and no rate', function () {
    $entry = phaseHistory([F::phase('a', PhaseType::FatLoss, '2026-05-04', '2026-06-01', -0.5)])[0];
    expect($entry->weightChangeKg)->toBeNull()
        ->and($entry->averageRatePercentPerWeek)->toBeNull();
});

it('ANA-006 a trend ending before the phase ends holds its last value', function () {
    // Weigh-ins stop on 10 September; the phase runs to 29 September. WT-010 holds the last value.
    $phase = F::phase('a', PhaseType::FatLoss, '2026-08-04', '2026-09-29', -0.5);
    $entry = phaseHistory([$phase], trend: F::trend(F::steady('2026-08-04', 38, 81)))[0];
    expect($entry->weightChangeKg)->toEqualWithDelta(0, 1e-9);
});

// ANA-008 mesocycles beside the weight change

it('ANA-008 each past phase lists its non-draft mesocycles in order', function () {
    $phases = [
        F::phase('a', PhaseType::FatLoss, '2026-08-04', '2026-09-29', -0.5),
        F::phase('b', PhaseType::Maintenance, '2026-09-29', null, 0),
    ];
    $mesos = [
        F::mesocycle('m2', 'a', '2026-09-01', 4),
        F::mesocycle('m1', 'a', '2026-08-04', 4),
        F::mesocycle('other', 'b', '2026-09-29', 4),
        F::mesocycle('none', null, '2026-08-04', 4),
    ];
    $list = phaseHistory($phases, $mesos);
    expect($list)->toHaveCount(1)
        ->and(array_map(fn (ProgressMesocycle $m) => $m->id, $list[0]->mesocycles))->toBe(['m1', 'm2'])
        ->and($list[0]->weightChangeKg)->not->toBeNull();
});

// ANA-007 mesocycle history

it('ANA-007 lists only mesocycles that have ended, newest first', function () {
    $phases = [F::phase('cut', PhaseType::FatLoss, '2026-08-04', null, -0.5)];
    $mesos = [
        // Ends on 2026-09-01 (4 weeks from 4 August): past.
        F::mesocycle('m1', 'cut', '2026-08-04', 4),
        // Ends exactly today, 10 October: past.
        F::mesocycle('m2', 'cut', '2026-09-12', 4),
        // Ends 17 October: still running.
        F::mesocycle('m3', 'cut', '2026-09-19', 4),
        // Starts in the future.
        F::mesocycle('m4', 'cut', '2026-10-12', 4),
    ];
    $list = MesocycleHistory::entries($mesos, $phases, [], F::day('2026-10-10'), F::zone());
    expect(array_map(fn ($e) => $e->id, $list))->toBe(['m2', 'm1'])
        ->and($list[1]->start)->toEqual(F::day('2026-08-04'))
        ->and($list[1]->end)->toEqual(F::day('2026-09-01'))
        ->and($list[1]->phaseType)->toBe(PhaseType::FatLoss);
});

it('ANA-007 average weekly sets per muscle', function () {
    $phases = [F::phase('cut', PhaseType::FatLoss, '2026-08-04', null, -0.5)];
    $mesos = [F::mesocycle('m1', 'cut', '2026-08-04', 4)];
    // Chest: 6 sets in week 1, 4 in week 2, none in week 3, 5 in week 4: 15 sets over the 3 weeks with any set.
    // Biceps: 3 sets in week 1, so 3 over the same 3 weeks.
    $sets = [
        ...loggedSets('m1', 1, MuscleGroup::Chest, 6), ...loggedSets('m1', 2, MuscleGroup::Chest, 4),
        ...loggedSets('m1', 4, MuscleGroup::Chest, 5), ...loggedSets('m1', 1, MuscleGroup::Biceps, 3),
        // Another mesocycle's sets do not count.
        ...loggedSets('other', 1, MuscleGroup::Chest, 50),
    ];
    $entry = MesocycleHistory::entries($mesos, $phases, $sets, F::day('2026-10-10'), F::zone())[0];

    // Weeks with any logged set: 1, 2 and 4.
    expect($entry->weeksWithSets)->toBe(3)
        ->and(array_map(fn (MuscleWeeklySets $s) => $s->muscle, $entry->weeklySets))->toBe([MuscleGroup::Chest, MuscleGroup::Biceps])
        ->and($entry->weeklySets[0]->averageSets)->toEqualWithDelta(5.0, 1e-9)
        ->and($entry->weeklySets[1]->averageSets)->toEqualWithDelta(1.0, 1e-9);
});

it('ANA-007 averages are rounded to one decimal', function () {
    $mesos = [F::mesocycle('m1', null, '2026-08-04', 4)];
    // 10 sets over 3 weeks is 3.333...
    $sets = [
        ...loggedSets('m1', 1, MuscleGroup::Quads, 4), ...loggedSets('m1', 2, MuscleGroup::Quads, 3),
        ...loggedSets('m1', 3, MuscleGroup::Quads, 3),
    ];
    $entry = MesocycleHistory::entries($mesos, [], $sets, F::day('2026-10-10'), F::zone())[0];
    expect($entry->weeklySets[0]->averageSets)->toEqualWithDelta(3.3, 1e-9)
        ->and($entry->phaseType)->toBeNull();
});

it('ANA-007 muscles follow the MESO-033 order', function () {
    $mesos = [F::mesocycle('m1', null, '2026-08-04', 4)];
    $sets = [
        ...loggedSets('m1', 1, MuscleGroup::Calves), ...loggedSets('m1', 1, MuscleGroup::Back),
        ...loggedSets('m1', 1, MuscleGroup::Chest),
    ];
    $entry = MesocycleHistory::entries($mesos, [], $sets, F::day('2026-10-10'), F::zone())[0];
    expect(array_map(fn (MuscleWeeklySets $s) => $s->muscle, $entry->weeklySets))
        ->toBe([MuscleGroup::Chest, MuscleGroup::Back, MuscleGroup::Calves]);
});

it('ANA-007 a mesocycle with no logged sets has no muscle rows', function () {
    $mesos = [F::mesocycle('m1', null, '2026-08-04', 4)];
    $entry = MesocycleHistory::entries($mesos, [], [], F::day('2026-10-10'), F::zone())[0];
    expect($entry->weeklySets)->toBe([])
        ->and($entry->weeksWithSets)->toBe(0);
});

it('ANA-006 a phase that ends the day it starts has no change and no rate', function () {
    $entry = phaseHistory([F::phase('a', PhaseType::FatLoss, '2026-09-01', '2026-09-01', -0.5)])[0];
    expect($entry->weightChangeKg)->toBeNull()
        ->and($entry->averageRatePercentPerWeek)->toBeNull();
});
