<?php

use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Model\PlanWeek;
use App\Domain\Plan\PlanWeekGrid;

// Where a mesocycle stands in time, the one answer the Train reads and the mesocycle writes share.
// rp-clone's MesocycleViewFixtures: the plan starts Monday 2026-09-14 and its weeks start on Monday;
// "Meso 2" is 5 weeks from that day, week 5 the deload (12 to 18 October).

function calDay(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

/** Monday plan weeks from 14 September; weekday 2 is Monday on the grid's Sunday-first count. */
function calGrid(): PlanWeekGrid
{
    return new PlanWeekGrid(calDay('2026-09-14'), 2);
}

function calMeso(string $today, int $weeks = 5, ?string $endedOn = null, string $start = '2026-09-14'): MesocycleCalendar
{
    return new MesocycleCalendar(calDay($start), $weeks, $endedOn === null ? null : calDay($endedOn), calGrid(), calDay($today));
}

it('MESO-042 the current week counts plan weeks from the start, held to the mesocycle\'s weeks', function () {
    expect(calMeso('2026-09-14')->currentWeek())->toBe(1)
        ->and(calMeso('2026-09-23')->currentWeek())->toBe(2)
        ->and(calMeso('2026-10-06')->currentWeek())->toBe(4)
        ->and(calMeso('2026-09-01')->currentWeek())->toBe(1)
        ->and(calMeso('2026-11-30')->currentWeek())->toBe(5);
});

it('MESO-042 the length runs from the current week plus one to eight', function () {
    $week2 = calMeso('2026-09-23');

    expect([$week2->lengthRange()->lower, $week2->lengthRange()->upper])->toBe([3, 8])
        ->and($week2->canShorten(laterWeeksStored: false))->toBeTrue()
        ->and($week2->canLengthen(laterWeeksStored: false))->toBeTrue();
});

it('MESO-042 shortening stops at the current week plus one and lengthening at eight', function () {
    $week4 = calMeso('2026-10-06');

    expect([$week4->lengthRange()->lower, $week4->lengthRange()->upper])->toBe([5, 8])
        ->and($week4->canShorten(laterWeeksStored: false))->toBeFalse()
        ->and($week4->canLengthen(laterWeeksStored: false))->toBeTrue()
        ->and(calMeso('2026-10-06', weeks: 8)->canLengthen(laterWeeksStored: false))->toBeFalse()
        ->and(calMeso('2026-10-06', weeks: 8)->canShorten(laterWeeksStored: false))->toBeTrue();
});

it('MESO-042 a length is allowed only inside the range', function () {
    $week4 = calMeso('2026-10-06');

    expect($week4->canSetWeeks(5, laterWeeksStored: false))->toBeTrue()
        ->and($week4->canSetWeeks(8, laterWeeksStored: false))->toBeTrue()
        ->and($week4->canSetWeeks(4, laterWeeksStored: false))->toBeFalse()
        ->and($week4->canSetWeeks(9, laterWeeksStored: false))->toBeFalse();
});

it('MESO-042 neither change is allowed during the deload week', function () {
    $deload = calMeso('2026-10-13');

    expect($deload->currentWeek())->toBe(5)
        ->and($deload->isInDeloadWeek())->toBeTrue()
        ->and($deload->canShorten(laterWeeksStored: false))->toBeFalse()
        ->and($deload->canLengthen(laterWeeksStored: false))->toBeFalse()
        ->and($deload->canSetWeeks(6, laterWeeksStored: false))->toBeFalse();
});

it('MESO-042 SCH-039 the length cannot change while later weeks are stored', function () {
    $week2 = calMeso('2026-09-23');

    expect($week2->canShorten(laterWeeksStored: true))->toBeFalse()
        ->and($week2->canLengthen(laterWeeksStored: true))->toBeFalse()
        ->and($week2->canSetWeeks(6, laterWeeksStored: true))->toBeFalse();
});

it('MESO-042 nothing changes once the mesocycle is over', function () {
    $over = calMeso('2026-11-30');

    expect($over->isRunning())->toBeFalse()
        ->and($over->canLengthen(laterWeeksStored: false))->toBeFalse()
        ->and($over->canSetWeeks(6, laterWeeksStored: false))->toBeFalse();
});

it('MESO-043 a mesocycle can be ended from its first day while it runs', function () {
    expect(calMeso('2026-09-10')->canEnd())->toBeFalse()
        ->and(calMeso('2026-09-14')->canEnd())->toBeTrue()
        ->and(calMeso('2026-10-06')->canEnd())->toBeTrue()
        ->and(calMeso('2026-11-30')->canEnd())->toBeFalse();
});

it('MESO-043 an ended mesocycle stops running the day it was ended', function () {
    $ended = calMeso('2026-10-06', endedOn: '2026-10-06');

    expect($ended->lastDay())->toEqual(calDay('2026-10-05'))
        ->and($ended->isRunning())->toBeFalse()
        ->and($ended->canEnd())->toBeFalse()
        ->and($ended->canAddDay())->toBeFalse();
});

it('MESO-045 days can be added only while the mesocycle runs', function () {
    expect(calMeso('2026-09-10')->canAddDay())->toBeTrue()
        ->and(calMeso('2026-10-06')->canAddDay())->toBeTrue()
        ->and(calMeso('2026-11-30')->canAddDay())->toBeFalse();
});

it('MESO-044 next plan week starts on the next review day', function () {
    expect(calMeso('2026-09-16')->nextPlanWeekStart())->toEqual(calDay('2026-09-21'))
        ->and(calMeso('2026-09-21')->nextPlanWeekStart())->toEqual(calDay('2026-09-28'));
});

it('MESO-060 the prompt runs from the first day of the deload week', function () {
    expect(calMeso('2026-10-11')->isNextMesocycleDue())->toBeFalse()
        ->and(calMeso('2026-10-12')->isNextMesocycleDue())->toBeTrue()
        ->and(calMeso('2026-11-30')->isNextMesocycleDue())->toBeTrue();
});

it('MESO-060 MESO-043 the prompt runs from the day an ended mesocycle was ended', function () {
    expect(calMeso('2026-10-06', endedOn: '2026-10-06')->isNextMesocycleDue())->toBeTrue()
        ->and(calMeso('2026-10-06', endedOn: '2026-10-08')->isNextMesocycleDue())->toBeFalse();
});

it('MESO-060 the next mesocycle starts the plan week after the deload', function () {
    expect(calMeso('2026-10-12')->nextMesocycleStart(activeRest: false))->toEqual(calDay('2026-10-19'));
});

it('MESO-060 MESO-043 after an early end the next mesocycle starts the plan week after the end', function () {
    expect(calMeso('2026-10-06', endedOn: '2026-10-06')->nextMesocycleStart(activeRest: false))->toEqual(calDay('2026-10-12'));
});

it('MESO-064 an active rest starts the next mesocycle one plan week later', function () {
    expect(calMeso('2026-10-12')->nextMesocycleStart(activeRest: true))->toEqual(calDay('2026-10-26'));
});

it('MESO-040 week columns are plan weeks; without a plan they run 7 days from the start', function () {
    expect(calMeso('2026-10-06')->planWeek(3)->start)->toEqual(calDay('2026-10-05'));

    $noPlan = new MesocycleCalendar(calDay('2026-09-16'), 5, null, MesocycleCalendar::gridWithoutPlan(calDay('2026-09-16')), calDay('2026-09-24'));

    expect($noPlan->planWeek(1)->start)->toEqual(calDay('2026-09-23'))
        ->and($noPlan->planWeek(1)->dayCount)->toBe(7)
        ->and($noPlan->currentWeek())->toBe(2);
});

it('MESO-005 NAV-002 a day with a weekday falls on it; floating days take the training weekdays in plan-week order', function () {
    // The plan week from Monday 5 October. The user trains Monday, Thursday and Saturday.
    $week = new PlanWeek(calDay('2026-10-05'));

    expect(MesocycleCalendar::dayDates($week, [4, 1], [6, 1, 4], 1))->toEqual([calDay('2026-10-08'), calDay('2026-10-05')])
        ->and(MesocycleCalendar::dayDates($week, [null, null, null], [6, 1, 4], 1))
        ->toEqual([calDay('2026-10-05'), calDay('2026-10-08'), calDay('2026-10-10')]);
});

it('MESO-005 a floating day with no training weekday left has no date', function () {
    $week = new PlanWeek(calDay('2026-10-05'));

    expect(MesocycleCalendar::dayDates($week, [null, null], [3], 1))->toEqual([calDay('2026-10-07'), null])
        // Plan weeks that start on Thursday put Thursday first.
        ->and(MesocycleCalendar::dayDates(new PlanWeek(calDay('2026-10-08')), [null, null], [1, 4], 4))
        ->toEqual([calDay('2026-10-08'), calDay('2026-10-12')]);
});

it('MESO-043 MESO-060 a mesocycle starting in a short first plan week runs to the end of its last plan week', function () {
    // The plan starts on Wednesday 16 September with Monday weeks, so its first week runs Wednesday to Sunday and
    // a 5-week mesocycle from that day has its deload from 12 to 18 October.
    $grid = new PlanWeekGrid(calDay('2026-09-16'), 2);
    $on = fn (string $today, ?string $endedOn = null) => new MesocycleCalendar(
        calDay('2026-09-16'), 5, $endedOn === null ? null : calDay($endedOn), $grid, calDay($today),
    );

    expect($on('2026-10-18')->lastDay())->toEqual(calDay('2026-10-18'))
        ->and($on('2026-10-18')->isRunning())->toBeTrue()
        ->and($on('2026-10-19')->nextMesocycleStart(activeRest: false))->toEqual(calDay('2026-10-19'))
        ->and($on('2026-10-19')->isRunning())->toBeFalse()
        ->and($on('2026-10-19')->canEnd())->toBeFalse()
        ->and($on('2026-10-19')->canAddDay())->toBeFalse()
        ->and($on('2026-10-07', endedOn: '2026-10-07')->lastDay())->toEqual(calDay('2026-10-06'));
});
