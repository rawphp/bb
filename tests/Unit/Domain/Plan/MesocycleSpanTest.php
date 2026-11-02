<?php

use App\Domain\Plan\MesocycleDates;
use App\Domain\Plan\MesocycleSpan;
use App\Domain\Plan\PlanWeekGrid;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

// MESO-043: a mesocycle's last day, the one answer for the list, the plan and the reminders. With a plan the weeks are
// its plan weeks (PLAN-008), so the grid form applies; without one, they run 7 days from the start.

it('MESO-043 a mesocycle runs its whole weeks by default', function () {
    // 5 weeks from Monday 5 October end on Sunday 8 November.
    expect(MesocycleSpan::lastDay(F::date(2026, 10, 5), 5, null))->toEqual(F::date(2026, 11, 8));
});

it('MESO-043 an ended mesocycle lasts until the day before it was ended', function () {
    expect(MesocycleSpan::lastDay(F::date(2026, 10, 5), 5, F::date(2026, 10, 20)))->toEqual(F::date(2026, 10, 19));
});

it('MESO-043 an end after the last week changes nothing', function () {
    expect(MesocycleSpan::lastDay(F::date(2026, 10, 5), 5, F::date(2026, 12, 1)))->toEqual(F::date(2026, 11, 8));
});

it('MESO-043 the stored mesocycle asks the same rule', function () {
    expect((new MesocycleDates(null, null, null))->lastDay())->toBeNull();

    $stored = new MesocycleDates(startDate: F::date(2026, 10, 5), weeks: 5, endedOn: F::date(2026, 10, 20));

    expect($stored->lastDay())->toEqual(F::date(2026, 10, 19));
});

it('MESO-043 only an ended mesocycle has a planned last day', function () {
    $running = new MesocycleDates(startDate: F::date(2026, 10, 5), weeks: 5, endedOn: null);
    $ended = new MesocycleDates(startDate: F::date(2026, 10, 5), weeks: 5, endedOn: F::date(2026, 10, 20));

    expect($running->plannedLastDay())->toBeNull()
        ->and($ended->plannedLastDay())->toEqual(F::date(2026, 10, 19));
});

it('MESO-043 the end is read on the calendar of the start', function () {
    // 02:00 UTC on 20 October is 22:00 on 19 October in New York: it was ended on the 19th, so it ran to the 18th.
    $start = F::date(2026, 10, 5, zone: 'America/New_York');
    $endedOn = F::date(2026, 10, 20, 2);

    expect(MesocycleSpan::lastDay($start, 5, $endedOn))->toEqual(F::date(2026, 10, 18, zone: 'America/New_York'));
});

it('MESO-043 PLAN-008 with a plan, a mesocycle starting in a short first plan week runs to the end of its last plan week', function () {
    // The plan starts on Wednesday 16 September with Monday weeks, so its first week runs Wednesday to Sunday and
    // a 5-week mesocycle from that day ends on Sunday 18 October, not Tuesday 20 October.
    $grid = new PlanWeekGrid(F::date(2026, 9, 16), F::MONDAY);

    expect(MesocycleSpan::lastDayOnGrid(F::date(2026, 9, 16), 5, null, $grid))->toEqual(F::date(2026, 10, 18))
        ->and(MesocycleSpan::lastDayOnGrid(F::date(2026, 9, 16), 5, F::date(2026, 10, 7), $grid))->toEqual(F::date(2026, 10, 6))
        ->and(MesocycleSpan::lastDayOnGrid(F::date(2026, 9, 16), 5, F::date(2026, 12, 1), $grid))->toEqual(F::date(2026, 10, 18));
});

it('MESO-043 PLAN-008 a plan starting on a Thursday with Monday weeks ends a 5-week mesocycle from its start on Sunday 1 November', function () {
    $grid = new PlanWeekGrid(F::date(2026, 10, 1), F::MONDAY);

    expect(MesocycleSpan::lastDayOnGrid(F::date(2026, 10, 1), 5, null, $grid))->toEqual(F::date(2026, 11, 1));
});

it('MESO-043 on 7-day weeks from the start the grid form is the plain one', function () {
    $grid = new PlanWeekGrid(F::date(2026, 10, 7), F::WEDNESDAY);

    expect(MesocycleSpan::lastDayOnGrid(F::date(2026, 10, 7), 5, null, $grid))->toEqual(MesocycleSpan::lastDay(F::date(2026, 10, 7), 5, null))
        // A mesocycle that starts before the plan counts whole weeks back from the plan's start.
        ->and(MesocycleSpan::lastDayOnGrid(F::date(2026, 9, 2), 2, null, $grid))->toEqual(F::date(2026, 9, 15));
});
