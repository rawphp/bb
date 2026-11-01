<?php

use App\Domain\Plan\PlannedMesocycle;
use App\Domain\Plan\PlanRules;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

// SCH-060: a paused week does not count as a mesocycle week. The mesocycle resumes where it stopped, ends one week
// later per pause, and every later mesocycle starts one week later.

/**
 * @param  list<?PlannedMesocycle>  $placed
 * @return list<?int>
 */
function startWeeks(array $placed): array
{
    return array_map(fn (?PlannedMesocycle $mesocycle) => $mesocycle?->startWeek, $placed);
}

it('SCH-060 after a paused week the mesocycle resumes where it stopped', function () {
    // Plan week 2 (the third) is paused: plan week 3 is mesocycle week 3 (index 2), not 4.
    expect(PlanRules::mesocycleWeek(1, startWeek: 0, pausedWeeks: [2]))->toBe(1)
        ->and(PlanRules::mesocycleWeek(3, startWeek: 0, pausedWeeks: [2]))->toBe(2)
        ->and(PlanRules::mesocycleWeek(4, startWeek: 0, pausedWeeks: [2]))->toBe(3)
        ->and(PlanRules::mesocycleWeek(3, startWeek: 0, pausedWeeks: []))->toBe(3);
});

it('SCH-060 a paused week before the mesocycle or after the week does not count', function () {
    expect(PlanRules::mesocycleWeek(5, startWeek: 4, pausedWeeks: [1, 6]))->toBe(1);
});

it('SCH-060 a mesocycle week maps back to its plan week', function () {
    expect(PlanRules::planWeek(1, startWeek: 0, pausedWeeks: [2]))->toBe(1)
        ->and(PlanRules::planWeek(2, startWeek: 0, pausedWeeks: [2]))->toBe(3)
        ->and(PlanRules::planWeek(3, startWeek: 0, pausedWeeks: [2]))->toBe(4)
        ->and(PlanRules::planWeek(2, startWeek: 0, pausedWeeks: []))->toBe(2);
});

it('SCH-060 the mesocycle ends one week later per pause', function () {
    $mesocycles = [F::span(0, 4)];
    $grid = F::grid();

    expect(PlanRules::coveringMesocycle(4, $mesocycles, $grid, [2])?->placed)
        ->toEqual(new PlannedMesocycle(startWeek: 0, weeks: 4, pausedWeeks: 1))
        ->and(PlanRules::coveringMesocycle(5, $mesocycles, $grid, [2]))->toBeNull()
        ->and(PlanRules::coveringMesocycle(5, $mesocycles, $grid, [1, 3])?->placed)
        ->toEqual(new PlannedMesocycle(startWeek: 0, weeks: 4, pausedWeeks: 2))
        ->and(PlanRules::coveringMesocycle(4, $mesocycles, $grid))->toBeNull();
});

it('SCH-060 every later mesocycle starts one week later', function () {
    $mesocycles = [F::span(0, 4), F::span(4, 4), F::span(8, 4)];
    $grid = F::grid();

    expect(startWeeks(PlanRules::placements($mesocycles, $grid, [2])))->toBe([0, 5, 9])
        ->and(PlanRules::coveringMesocycle(4, $mesocycles, $grid, [2])?->index)->toBe(0)
        ->and(PlanRules::coveringMesocycle(5, $mesocycles, $grid, [2])?->index)->toBe(1);
});

it('SCH-060 a pause in a later mesocycle leaves the earlier one alone', function () {
    $placed = PlanRules::placements([F::span(0, 4), F::span(4, 4)], F::grid(), [6]);

    expect(startWeeks($placed))->toBe([0, 4])
        ->and($placed[1]?->pausedWeeks)->toBe(1);
});

it('SCH-060 a gap after the mesocycle absorbs the shift', function () {
    expect(startWeeks(PlanRules::placements([F::span(0, 4), F::span(6, 4)], F::grid(), [2])))->toBe([0, 6]);
});

it('SCH-060 an overlap that already existed is not pushed out', function () {
    // An ended-early mesocycle: the next one began inside its raw weeks.
    $mesocycles = [F::span(0, 6), F::span(4, 4)];

    expect(startWeeks(PlanRules::placements($mesocycles, F::grid())))->toBe([0, 4])
        // It moves only by how far the earlier one grew.
        ->and(startWeeks(PlanRules::placements($mesocycles, F::grid(), [1])))->toBe([0, 5]);
});

it('SCH-060 a pause inside an existing overlap belongs to the later mesocycle', function () {
    // Week 5 is inside both: it is the later mesocycle's week, so it pushes nothing.
    $placed = PlanRules::placements([F::span(0, 6), F::span(4, 4)], F::grid(), [5]);

    expect(startWeeks($placed))->toBe([0, 4])
        ->and($placed[1]?->pausedWeeks)->toBe(1);
});

it("SCH-060 a pause on a mesocycle's first week moves its start one later", function () {
    $mesocycles = [F::span(0, 4), F::span(4, 4)];
    $placed = PlanRules::placements($mesocycles, F::grid(), [4]);

    expect(startWeeks($placed))->toBe([0, 5])
        ->and($placed[1]?->pausedWeeks)->toBe(0)
        ->and(PlanRules::coveringMesocycle(4, $mesocycles, F::grid(), [4]))->toBeNull()
        ->and(PlanRules::coveringMesocycle(8, $mesocycles, F::grid(), [4])?->index)->toBe(1);
});

it('SCH-060 a paused first week of the first mesocycle starts it a week later', function () {
    $placed = PlanRules::placements([F::span(0, 4)], F::grid(), [0]);

    expect($placed[0]?->startWeek)->toBe(1)
        ->and($placed[0]?->weeks)->toBe(4);
});

it('SCH-060 an unfinished mesocycle has no place', function () {
    $placed = PlanRules::placements([null, F::span(0, 4)], F::grid(), [2]);

    expect($placed[0])->toBeNull()
        ->and($placed[1]?->pausedWeeks)->toBe(1);
});
