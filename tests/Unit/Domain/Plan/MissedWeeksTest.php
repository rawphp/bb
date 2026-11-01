<?php

use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\MissedWeeks;
use App\Domain\Plan\MissedWeeks\Week;
use App\Domain\Plan\MovedDeload;
use App\Domain\Plan\PlanRules;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

// SCH-058: missing a week or more of training asks to restart the week or end the mesocycle.

function missedWeek(int $index, int $sessions = 3, int $started = 0): Week
{
    return new Week($index, $sessions, $started);
}

// When it asks.

it('SCH-058 a whole week with sessions and none started is due', function () {
    $weeks = [missedWeek(0, started: 3), missedWeek(1), missedWeek(2)];

    expect(MissedWeeks::isDue($weeks, lastStartedWeek: 0, currentWeek: 2))->toBeTrue();
});

it('SCH-058 the week in progress does not count', function () {
    $weeks = [missedWeek(0, started: 3), missedWeek(1)];

    expect(MissedWeeks::isDue($weeks, lastStartedWeek: 0, currentWeek: 1))->toBeFalse();
});

it('SCH-058 the week of the last started session does not count', function () {
    // One session of week 1 was started; the other two were not.
    $weeks = [missedWeek(0, started: 3), missedWeek(1, started: 1), missedWeek(2)];

    expect(MissedWeeks::isDue($weeks, lastStartedWeek: 1, currentWeek: 2))->toBeFalse();
});

it('SCH-058 a week that placed no sessions does not count', function () {
    // A paused away week places none.
    $weeks = [missedWeek(0, started: 3), missedWeek(1, sessions: 0), missedWeek(2)];

    expect(MissedWeeks::isDue($weeks, lastStartedWeek: 0, currentWeek: 2))->toBeFalse();
});

it('SCH-058 a week whose sessions were started long ago does not count', function () {
    // After a restart, weeks already trained sit after the old start dates of their sessions.
    $weeks = [missedWeek(3, started: 3), missedWeek(4, started: 2), missedWeek(5)];

    expect(MissedWeeks::isDue($weeks, lastStartedWeek: 1, currentWeek: 5))->toBeFalse();
});

it('SCH-058 with no session started ever an ended week is due', function () {
    expect(MissedWeeks::isDue([missedWeek(0), missedWeek(1)], lastStartedWeek: null, currentWeek: 1))->toBeTrue();
});

it('SCH-058 a week of only skipped sessions counts', function () {
    // Skipped counts as not started.
    expect(MissedWeeks::isDue([missedWeek(0, started: 3), missedWeek(1), missedWeek(2)], lastStartedWeek: 0, currentWeek: 2))
        ->toBeTrue();
});

it('SCH-058 a week before the last started one does not count', function () {
    expect(MissedWeeks::isDue([missedWeek(0), missedWeek(1), missedWeek(2)], lastStartedWeek: 1, currentWeek: 2))->toBeFalse();
});

it('SCH-058 a week with a started session does not count even after the last started week', function () {
    // After a restart, a week trained long ago can sit after the week of the last started session.
    expect(MissedWeeks::isDue([missedWeek(3, started: 1), missedWeek(4)], lastStartedWeek: 1, currentWeek: 4))->toBeFalse();
});

// Restart.

it('SCH-058 restart moves the start so the stopped week is the current one', function () {
    // Started in plan week 0; stopped in its week 2 (index 1), plan week 1; back in plan week 4.
    expect(MissedWeeks::restartShift(1, 0, [F::span(0, 5)], currentWeek: 4, grid: F::grid()))->toBe(3);
});

it('SCH-058 a paused week between is already counted in the shift', function () {
    // Plan week 2 was a paused away week: mesocycle week index 2 sat on plan week 3. Back in plan week 5.
    expect(MissedWeeks::restartShift(2, 0, [F::span(0, 5)], currentWeek: 5, grid: F::grid(), pausedWeeks: [2]))->toBe(2);
});

it('SCH-058 a paused current week puts the stopped week on the next one', function () {
    $shift = MissedWeeks::restartShift(1, 0, [F::span(0, 5)], currentWeek: 4, grid: F::grid(), pausedWeeks: [4]);

    // Starting in plan week 3, its week index 1 skips the paused plan week 4 and sits on plan week 5.
    expect($shift)->toBe(3)
        ->and(PlanRules::planWeek(1, startWeek: 3, pausedWeeks: [4]))->toBe(5);
});

it('SCH-058 a stopped week already in the current one moves nothing', function () {
    expect(MissedWeeks::restartShift(2, 0, [F::span(0, 5)], currentWeek: 2, grid: F::grid()))->toBe(0);
});

it('SCH-058 the other mesocycles are laid out with it', function () {
    // A pause in plan week 1 stretches the first mesocycle to plan week 3, so the second, stored from plan week 3,
    // sits on plan week 4. Back in plan week 6, its stored start moves 3 weeks, not 2.
    $mesocycles = [F::span(0, 3), F::span(3, 4)];

    $shift = MissedWeeks::restartShift(0, 1, $mesocycles, currentWeek: 6, grid: F::grid(), pausedWeeks: [1]);
    expect($shift)->toBe(3);

    $moved = [$mesocycles[0], new DatedMesocycle($mesocycles[1]->start->modify('+'.(7 * $shift).' days'), 4)];
    expect(PlanRules::placements($moved, F::grid(), [1])[1]?->startWeek)->toBe(6);
});

it('SCH-058 a flagged deload moves with its mesocycle week', function () {
    // A 4-week mesocycle from plan week 0 with its deload flagged on plan week 3, restarted from plan week 2.
    $moves = MissedWeeks::movedDeloads([3], 0, [F::span(0, 4)], restartedOn: F::grid()->start(2), grid: F::grid());

    expect($moves)->toEqual([new MovedDeload(from: 3, to: 5)]);
});

it('SCH-058 a paused week keeps the deload on its mesocycle week', function () {
    // Plan week 1 is paused, so mesocycle week index 3 sat on plan week 4; restarted from plan week 2 it falls on 5.
    $moves = MissedWeeks::movedDeloads(
        [4], 0, [F::span(0, 4)], restartedOn: F::grid()->start(2), grid: F::grid(), pausedWeeks: [1],
    );

    expect(array_map(fn (MovedDeload $move) => $move->to, $moves))->toBe([5]);
});

it('SCH-058 a flag outside the mesocycle stays', function () {
    expect(MissedWeeks::movedDeloads([4, 9], 0, [F::span(0, 4)], restartedOn: F::grid()->start(2), grid: F::grid()))
        ->toBe([]);
});
