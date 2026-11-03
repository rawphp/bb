<?php

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\AwayWeekRow;
use App\Domain\Plan\AwayWeeks;
use App\Domain\Plan\DatedMesocycle;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

// SCH-060 and the away-week part of PLAN-025 (rp-clone TodayModel+Away and AwayWeekModel). The plan starts Monday
// 2026-01-05; the day in progress is Wednesday of week 2 unless a test says otherwise.

/**
 * @param  array<int, AwayWeekRow>  $rows
 * @param  list<DatedMesocycle>  $mesocycles
 */
function awayWeeks(?Phase $phase = null, array $rows = [], array $mesocycles = [], int $currentWeek = 2, array $later = []): AwayWeeks
{
    $phase ??= F::phase(PhaseType::FatLoss, 0, 12);
    $grid = F::grid();

    return new AwayWeeks(F::plan([$phase, ...$later]), $grid, $grid->start($currentWeek)->modify('+2 days'), $mesocycles, $rows);
}

function awayRow(Training $training = Training::Pause, Calories $calories = Calories::Maintenance, bool $dietBreak = true, bool $keep = false): AwayWeekRow
{
    return new AwayWeekRow(true, $training, $calories, $dietBreak, $keep);
}

// Which weeks.

it('SCH-060 offers every week from next week to the last week of the phase', function () {
    expect(awayWeeks()->offered())->toBe([3, 4, 5, 6, 7, 8, 9, 10, 11]);
});

it('SCH-060 a phase with no end offers twelve weeks ahead', function () {
    $weeks = awayWeeks(F::phase(PhaseType::FatLoss, 0, null))->offered();

    expect($weeks)->toHaveCount(12)
        ->and($weeks[0])->toBe(3)
        ->and($weeks[11])->toBe(14);
});

it('SCH-060 the week in progress and past weeks are not offered', function () {
    $away = awayWeeks();

    expect($away->isOffered(2))->toBeFalse()
        ->and($away->isOffered(0))->toBeFalse()
        ->and($away->isOffered(3))->toBeTrue()
        ->and($away->isOffered(11))->toBeTrue()
        ->and($away->isOffered(12))->toBeFalse();
});

it('SCH-060 nothing is offered in the last week of the phase, before the plan, or without a covering phase', function () {
    expect(awayWeeks(F::phase(PhaseType::FatLoss, 0, 3))->offered())->toBe([])
        ->and(awayWeeks(F::phase(PhaseType::FatLoss, 5, 12))->offered())->toBe([])
        ->and((new AwayWeeks(F::plan([F::phase(PhaseType::FatLoss, 0, 12)]), F::grid(), F::date(2025, 12, 20), [], []))->offered())->toBe([]);
});

it('SCH-060 an away week is still offered, to be edited', function () {
    expect(awayWeeks(rows: [4 => awayRow()])->isOffered(4))->toBeTrue();
});

// The deload.

it('SCH-060 a deload is offered with three training weeks of its mesocycle before it', function () {
    $away = awayWeeks(mesocycles: [F::span(0, 5)], currentWeek: 0);

    expect($away->deloadOffered([3]))->toBeTrue()
        ->and($away->deloadOffered([2]))->toBeFalse();
});

it('SCH-060 a deload is offered only if every marked week qualifies', function () {
    $away = awayWeeks(mesocycles: [F::span(0, 5)], currentWeek: 0);

    expect($away->deloadOffered([2, 3]))->toBeFalse()
        ->and($away->deloadOffered([]))->toBeFalse();
});

it('SCH-060 a week outside any mesocycle cannot be a deload', function () {
    expect(awayWeeks(mesocycles: [F::span(0, 4)], currentWeek: 0)->deloadOffered([6]))->toBeFalse();
});

it('SCH-060 a paused week before it does not count toward the three', function () {
    $away = awayWeeks(rows: [2 => awayRow()], mesocycles: [F::span(0, 6)], currentWeek: 0);

    expect($away->deloadOffered([3]))->toBeFalse()
        ->and($away->deloadOffered([4]))->toBeTrue();
});

it('SCH-060 an away deload before it does not count toward the three', function () {
    $away = awayWeeks(rows: [0 => awayRow(Training::Deload)], mesocycles: [F::span(0, 6)], currentWeek: 0);

    expect($away->deloadOffered([3]))->toBeFalse();
});

it('SCH-060 a marked paused week before it does not count either', function () {
    // Pausing week 2 and making week 3 the deload would leave two training weeks before the deload.
    $away = awayWeeks(mesocycles: [F::span(0, 6)], currentWeek: 0);

    expect($away->deloadOffered([3]))->toBeTrue()
        ->and($away->deloadOffered([2, 3]))->toBeFalse();
});

// PLAN-025: the diet break each choice makes.

it('SCH-060 PLAN-025 maintenance in Fat loss is a diet break, and so is it in a Mini-cut', function () {
    expect(awayWeeks()->isDietBreakChoice(true, Calories::Maintenance))->toBeTrue()
        ->and(awayWeeks(F::phase(PhaseType::MiniCut, 0, 12))->isDietBreakChoice(true, Calories::Maintenance))->toBeTrue()
        ->and(awayWeeks()->isDietBreakChoice(true, Calories::RestDays))->toBeFalse()
        ->and(awayWeeks()->isDietBreakChoice(false, Calories::Maintenance))->toBeFalse()
        ->and(awayWeeks(F::phase(PhaseType::MuscleGain, 0, 12))->isDietBreakChoice(true, Calories::Maintenance))->toBeFalse();
});

it('PLAN-025 a week that becomes a diet break adds one', function () {
    expect(awayWeeks()->change(4, true, Calories::Maintenance))->toBe(1)
        ->and(awayWeeks()->change(4, true, Calories::RestDays))->toBe(0);
});

it('PLAN-025 a week that is a diet break already adds none', function () {
    $away = awayWeeks(rows: [4 => new AwayWeekRow(isDietBreak: true)]);

    expect($away->change(4, true, Calories::Maintenance))->toBe(0)
        ->and($away->storedDietBreak(4, true, Calories::Maintenance))->toBeTrue();
});

it('PLAN-025 an away diet break that stops being one takes one off', function () {
    $away = awayWeeks(rows: [4 => awayRow()]);

    expect($away->change(4, true, Calories::RestDays))->toBe(-1)
        ->and($away->change(4, false, Calories::Maintenance))->toBe(-1)
        ->and($away->change(4, true, Calories::Maintenance))->toBe(0)
        ->and($away->storedDietBreak(4, true, Calories::RestDays))->toBeFalse()
        ->and($away->storedDietBreak(4, false, Calories::Maintenance))->toBeFalse();
});

it('PLAN-025 a diet break set some other way stays one and moves nothing', function () {
    $away = awayWeeks(rows: [4 => awayRow(calories: Calories::RestDays, dietBreak: true)]);

    expect($away->change(4, true, Calories::RestDays))->toBe(0)
        ->and($away->change(4, false, Calories::RestDays))->toBe(0)
        ->and($away->storedDietBreak(4, true, Calories::RestDays))->toBeTrue()
        ->and($away->storedDietBreak(4, false, Calories::RestDays))->toBeTrue();
});

// PLAN-025: the end date.

it('PLAN-025 the phase end moves a week for each diet break, and the later phases with it', function () {
    $later = F::phase(PhaseType::MuscleGain, 12, 20, id: 'later');
    $away = awayWeeks(later: [$later]);

    $moves = $away->movedPhases(2);

    expect($moves)->toHaveCount(2)
        ->and($moves[0]->phase->id)->toBe('phase')
        ->and($moves[0]->phase->start)->toEqual(F::grid()->start(0))
        ->and($moves[0]->phase->end)->toEqual(F::grid()->start(14))
        ->and($moves[1]->phase->id)->toBe('later')
        ->and($moves[1]->phase->start)->toEqual(F::grid()->start(14))
        ->and($moves[1]->phase->end)->toEqual(F::grid()->start(22))
        ->and($moves[0]->exists)->toBeTrue();
});

it('PLAN-025 each diet break undone brings the end a week sooner', function () {
    expect(awayWeeks()->movedPhases(-1)[0]->phase->end)->toEqual(F::grid()->start(11));
});

it('PLAN-025 a phase with no end moves nothing, and neither does a net of none', function () {
    expect(awayWeeks(F::phase(PhaseType::FatLoss, 0, null))->movedPhases(1))->toBe([])
        ->and(awayWeeks()->movedPhases(0))->toBe([]);
});

// What a save moves the end by, and the keep_phase_end it stores: the end never drifts from the breaks that moved it.

it('PLAN-025 the end moves out once per diet break that has not moved it, and back only for one that has', function (?AwayWeekRow $row, bool $away, Calories $calories, bool $keep, array $expected) {
    expect(awayWeeks(rows: $row === null ? [] : [4 => $row])->endMove(4, $away, $calories, $keep))->toBe($expected);
})->with([
    'a new break, end moves' => [null, true, Calories::Maintenance, false, [1, false]],
    'a new break, end kept' => [null, true, Calories::Maintenance, true, [0, true]],
    'rest days, no break' => [null, true, Calories::RestDays, false, [0, false]],
    'a moved break stays' => [awayRow(keep: false), true, Calories::Maintenance, false, [0, false]],
    'a moved break stays, keep sent' => [awayRow(keep: false), true, Calories::Maintenance, true, [0, false]],
    'a kept break, keep turned off' => [awayRow(keep: true), true, Calories::Maintenance, false, [1, false]],
    'a kept break stays kept' => [awayRow(keep: true), true, Calories::Maintenance, true, [0, true]],
    'a moved break to rest days' => [awayRow(keep: false), true, Calories::RestDays, false, [-1, false]],
    'a moved break to rest days, keep sent' => [awayRow(keep: false), true, Calories::RestDays, true, [-1, true]],
    'a moved break not away, keep sent' => [awayRow(keep: false), false, Calories::Maintenance, true, [-1, false]],
    'a kept break to rest days, keep off' => [awayRow(keep: true), true, Calories::RestDays, false, [0, false]],
    'a moved break not away' => [awayRow(keep: false), false, Calories::Maintenance, false, [-1, false]],
    'a kept break not away, keep off' => [awayRow(keep: true), false, Calories::Maintenance, false, [0, false]],
    'a break from elsewhere marked away' => [new AwayWeekRow(false, null, null, true, false), true, Calories::Maintenance, false, [0, false]],
]);
