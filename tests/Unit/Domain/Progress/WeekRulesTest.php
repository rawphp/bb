<?php

use App\Domain\Model\StepPlanDay;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\DayAdherence;
use App\Domain\Progress\DayTotals;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Progress\ReviewAvailability;
use App\Domain\Progress\StepProgress;
use App\Domain\Progress\StepProgressState;
use Tests\Unit\Domain\Progress\ProgressFixtures as F;

// 2026-10-10 is a Saturday (ISO 6).

// ANA-003 the current plan week

it('ANA-003 iso weekday', function () {
    expect(PlanWeekDays::isoWeekday(F::day('2026-10-10'), F::zone()))->toBe(6)
        ->and(PlanWeekDays::isoWeekday(F::day('2026-10-11'), F::zone()))->toBe(7)
        ->and(PlanWeekDays::isoWeekday(F::day('2026-10-12'), F::zone()))->toBe(1);
});

it('ANA-003 the week starts on the latest review weekday on or before today', function () {
    // Review on Saturday, today Saturday: the week starts today.
    expect(PlanWeekDays::currentWeek(F::day('2026-10-10'), 6, F::zone()))
        ->toEqual(array_map(fn (int $i) => F::day('2026-10-10', $i), range(0, 6)));
    // Review on Monday, today Saturday: the week started Monday 5 October.
    $monday = PlanWeekDays::currentWeek(F::day('2026-10-10'), 1, F::zone());
    expect($monday[0])->toEqual(F::day('2026-10-05'))
        ->and($monday[6])->toEqual(F::day('2026-10-11'))
        ->and($monday)->toHaveCount(7)
        // Review on Sunday, today Saturday: it started last Sunday, 4 October.
        ->and(PlanWeekDays::currentWeek(F::day('2026-10-10'), 7, F::zone())[0])->toEqual(F::day('2026-10-04'))
        // Review on Sunday, today Sunday: today.
        ->and(PlanWeekDays::currentWeek(F::day('2026-10-11'), 7, F::zone())[0])->toEqual(F::day('2026-10-11'));
});

// MESO-005 / SCH-040 weekdays

it('MESO-005 iso and calendar weekdays convert both ways', function () {
    // Calendar: 1 = Sunday ... 7 = Saturday. ISO: 1 = Monday ... 7 = Sunday.
    expect(array_map(PlanWeekDays::calendarWeekdayFromIso(...), range(1, 7)))->toBe([2, 3, 4, 5, 6, 7, 1])
        ->and(array_map(PlanWeekDays::isoWeekdayFromCalendar(...), range(1, 7)))->toBe([7, 1, 2, 3, 4, 5, 6]);
    foreach (range(1, 7) as $iso) {
        expect(PlanWeekDays::isoWeekdayFromCalendar(PlanWeekDays::calendarWeekdayFromIso($iso)))->toBe($iso);
    }
});

it('SCH-040 training weekdays run in plan-week order from the review day', function () {
    expect(PlanWeekDays::inPlanWeekOrder([7, 2, 6, 4], 1))->toBe([2, 4, 6, 7])
        // A Saturday review day starts the week on Saturday.
        ->and(PlanWeekDays::inPlanWeekOrder([2, 4, 6, 7], 6))->toBe([6, 7, 2, 4])
        // The review day itself, when it is a training day, comes first.
        ->and(PlanWeekDays::inPlanWeekOrder([3, 1, 5], 3))->toBe([3, 5, 1])
        ->and(PlanWeekDays::inPlanWeekOrder([], 3))->toBe([]);
});

// ANA-004 review card

it('ANA-004 card title', function () {
    expect(ReviewAvailability::CARD_TITLE)->toBe('Weekly review available');
});

// ANA-011 adherence (DAY-010, SCH-021)

function adherenceOn(
    string $day, ?DayTotals $consumed, string $today = '2026-10-10', string $planStart = '2026-08-04',
    ?DayTotals $target = new DayTotals(2000, 150),
): DayAdherence {
    return Adherence::status(F::day($day), F::day($today), F::day($planStart), $target, $consumed, F::zone());
}

it('ANA-011 a past day within ten percent on both gets the green dot', function () {
    expect(adherenceOn('2026-10-08', new DayTotals(2000, 150)))->toBe(DayAdherence::OnTrack)
        // Exactly 10% either way still counts.
        ->and(adherenceOn('2026-10-08', new DayTotals(2200, 135)))->toBe(DayAdherence::OnTrack)
        ->and(adherenceOn('2026-10-08', new DayTotals(1800, 165)))->toBe(DayAdherence::OnTrack);
});

it('ANA-011 no dot when calories or protein are outside', function () {
    expect(adherenceOn('2026-10-08', new DayTotals(2201, 150)))->toBe(DayAdherence::NoDot)
        ->and(adherenceOn('2026-10-08', new DayTotals(1799, 150)))->toBe(DayAdherence::NoDot)
        ->and(adherenceOn('2026-10-08', new DayTotals(2000, 134)))->toBe(DayAdherence::NoDot)
        ->and(adherenceOn('2026-10-08', new DayTotals(2000, 166)))->toBe(DayAdherence::NoDot);
});

it('ANA-011 today and future days never get the dot', function () {
    $perfect = new DayTotals(2000, 150);
    expect(adherenceOn('2026-10-10', $perfect))->toBe(DayAdherence::NoDot)
        ->and(adherenceOn('2026-10-11', $perfect))->toBe(DayAdherence::NoDot);
});

it('ANA-011 no consumed data or no target means no dot', function () {
    expect(adherenceOn('2026-10-08', null))->toBe(DayAdherence::NoDot)
        ->and(adherenceOn('2026-10-08', new DayTotals(2000, 150), target: null))->toBe(DayAdherence::NoDot);
});

it('ANA-011 days before the plan start show a dash', function () {
    expect(adherenceOn('2026-08-03', null))->toBe(DayAdherence::BeforePlan)
        ->and(adherenceOn('2026-08-03', new DayTotals(2000, 150)))->toBe(DayAdherence::BeforePlan)
        // The start day itself is in the plan.
        ->and(adherenceOn('2026-08-04', null, today: '2026-08-10'))->toBe(DayAdherence::NoDot);
});

// ANA-005 steps

/** @return list<StepPlanDay> */
function stepPlan(): array
{
    return [new StepPlanDay(weekday: 1, min: 8000, max: 10000), new StepPlanDay(weekday: 6, min: 6000, max: 8000)];
}

it('ANA-005 steps against today\'s range', function () {
    $steps = StepProgress::make(7000, stepPlan(), 6);
    expect($steps)->not->toBeNull()
        ->and($steps->actual)->toBe(7000)
        ->and($steps->targetMin)->toBe(6000)
        ->and($steps->targetMax)->toBe(8000)
        ->and($steps->state())->toBe(StepProgressState::WithinRange)
        ->and($steps->fraction())->toEqualWithDelta(0.875, 1e-9);
});

it('ANA-005 below and above the range', function () {
    $above = StepProgress::make(9500, stepPlan(), 6);
    expect(StepProgress::make(2000, stepPlan(), 6)?->state())->toBe(StepProgressState::BelowRange)
        ->and($above?->state())->toBe(StepProgressState::AboveRange)
        ->and($above?->fraction())->toEqualWithDelta(1, 1e-9);
});

it('ANA-005 hidden without step data or a range for today', function () {
    expect(StepProgress::make(null, stepPlan(), 6))->toBeNull()
        ->and(StepProgress::make(5000, stepPlan(), 3))->toBeNull()
        ->and(StepProgress::make(5000, [], 6))->toBeNull();
});

it('ANA-005 a range with no top counts as full rather than dividing by zero', function () {
    $steps = StepProgress::make(0, [new StepPlanDay(weekday: 6, min: 0, max: 0)], 6);
    expect($steps?->fraction())->toBe(1.0)
        ->and($steps?->state())->toBe(StepProgressState::WithinRange);
});
