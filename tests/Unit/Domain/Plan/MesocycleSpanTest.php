<?php

use App\Domain\Plan\MesocycleDates;
use App\Domain\Plan\MesocycleSpan;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

// MESO-043: a mesocycle's last day, the one answer for the list, the plan and the reminders.

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
