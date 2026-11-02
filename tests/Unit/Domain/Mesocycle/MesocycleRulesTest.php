<?php

use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\PlanWeekGrid;

// The mesocycle rules the Train reads and the mesocycle writes share, value in and value out. The plan starts
// Monday 2026-09-14 and its weeks start on Monday (weekday 2 on the grid's Sunday-first count).

function rulesDay(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

function rulesGrid(): PlanWeekGrid
{
    return new PlanWeekGrid(rulesDay('2026-09-14'), 2);
}

it('MESO-004 a default start is today on a plan-week boundary, else the next plan week', function () {
    expect(MesocycleRules::defaultStart(rulesGrid(), rulesDay('2026-09-21')))->toEqual(rulesDay('2026-09-21'))
        ->and(MesocycleRules::defaultStart(rulesGrid(), rulesDay('2026-09-23')))->toEqual(rulesDay('2026-09-28'))
        // Before the plan, the plan's first day.
        ->and(MesocycleRules::defaultStart(rulesGrid(), rulesDay('2026-09-01')))->toEqual(rulesDay('2026-09-14'))
        // Without a plan, today.
        ->and(MesocycleRules::defaultStart(null, rulesDay('2026-09-23')))->toEqual(rulesDay('2026-09-23'));
});

it('MESO-004 a start snaps to the first day of its plan week', function () {
    expect(MesocycleRules::snapped(rulesGrid(), rulesDay('2026-09-24')))->toEqual(rulesDay('2026-09-21'))
        ->and(MesocycleRules::snapped(rulesGrid(), rulesDay('2026-09-21')))->toEqual(rulesDay('2026-09-21'))
        ->and(MesocycleRules::snapped(null, rulesDay('2026-09-24')))->toEqual(rulesDay('2026-09-24'));
});

it('MESO-060 MESO-004 the next mesocycle never starts before the default start', function () {
    expect(MesocycleRules::nextMesocycleStart(rulesDay('2026-10-19'), rulesDay('2026-10-12')))->toEqual(rulesDay('2026-10-19'))
        ->and(MesocycleRules::nextMesocycleStart(rulesDay('2026-03-16'), rulesDay('2026-11-02')))->toEqual(rulesDay('2026-11-02'));
});

it('MESO-060 a copy of the mesocycle ending starts with the next one, any other copy at the default start', function () {
    expect(MesocycleRules::copyStart(rulesDay('2026-10-19'), rulesDay('2026-10-12')))->toEqual(rulesDay('2026-10-19'))
        ->and(MesocycleRules::copyStart(null, rulesDay('2026-10-12')))->toEqual(rulesDay('2026-10-12'));
});

it('MESO-065 a copy into a phase of another type takes that phase\'s preset', function () {
    expect(MesocycleRules::priorityReset(PhaseType::MuscleGain, PhaseType::FatLoss))->toBe(PhaseType::FatLoss)
        ->and(MesocycleRules::priorityReset(PhaseType::FatLoss, PhaseType::FatLoss))->toBeNull()
        ->and(MesocycleRules::priorityReset(null, PhaseType::FatLoss))->toBeNull()
        ->and(MesocycleRules::priorityReset(PhaseType::MuscleGain, null))->toBeNull();
});

it('MESO-044 ending offers a diet break in fat loss, unless this week is one', function () {
    expect(MesocycleRules::endOffersDietBreak(PhaseType::FatLoss, currentWeekIsDietBreak: false))->toBeTrue()
        ->and(MesocycleRules::endOffersDietBreak(PhaseType::FatLoss, currentWeekIsDietBreak: true))->toBeFalse()
        ->and(MesocycleRules::endOffersDietBreak(PhaseType::MuscleGain, currentWeekIsDietBreak: false))->toBeFalse()
        ->and(MesocycleRules::endOffersDietBreak(null, currentWeekIsDietBreak: false))->toBeFalse();
});

it('MESO-041 PLAN-022 a week is a diet break when flagged, or a fat loss deload not turned off', function () {
    expect(MesocycleRules::isDietBreakWeek(flagged: true, phase: null, isDeload: false, turnedOff: false))->toBeTrue()
        ->and(MesocycleRules::isDietBreakWeek(flagged: false, phase: PhaseType::FatLoss, isDeload: true, turnedOff: false))->toBeTrue()
        ->and(MesocycleRules::isDietBreakWeek(flagged: false, phase: PhaseType::FatLoss, isDeload: true, turnedOff: true))->toBeFalse()
        ->and(MesocycleRules::isDietBreakWeek(flagged: false, phase: PhaseType::MuscleGain, isDeload: true, turnedOff: false))->toBeFalse()
        ->and(MesocycleRules::isDietBreakWeek(flagged: false, phase: null, isDeload: true, turnedOff: false))->toBeFalse();
});

it('MESO-005 a day of a weekday split is named after its ISO weekday', function () {
    expect(MesocycleRules::weekdayName(1))->toBe('Monday')
        ->and(MesocycleRules::weekdayName(7))->toBe('Sunday');
});
