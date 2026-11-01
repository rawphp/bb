<?php

use App\Domain\Model\Plan;
use App\Domain\Plan\PlanWeekGrid;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

it('PLAN-008 a plan starting on the review day has seven-day weeks', function () {
    $grid = F::grid();

    expect($grid->reviewWeekday())->toBe(F::MONDAY)
        ->and($grid->start(0))->toEqual(F::date(2026, 1, 5))
        ->and($grid->start(1))->toEqual(F::date(2026, 1, 12))
        ->and($grid->start(10))->toEqual(F::date(2026, 3, 16))
        ->and($grid->lengthInDays(0))->toBe(7)
        ->and($grid->lengthInDays(9))->toBe(7);
});

it('PLAN-008 a plan starting mid-week has a short first week', function () {
    // Thursday 2026-01-01, review day Monday: week 0 is Thu to Sun, 4 days.
    $grid = new PlanWeekGrid(F::date(2026, 1, 1), F::MONDAY);

    expect($grid->start(0))->toEqual(F::date(2026, 1, 1))
        ->and($grid->start(1))->toEqual(F::date(2026, 1, 5))
        ->and($grid->start(2))->toEqual(F::date(2026, 1, 12))
        ->and($grid->lengthInDays(0))->toBe(4)
        ->and($grid->lengthInDays(1))->toBe(7);
});

it('PLAN-008 builds from a plan', function () {
    $plan = new Plan(id: 'plan', start: F::date(2026, 1, 1), reviewWeekday: F::MONDAY, phases: []);
    $grid = PlanWeekGrid::forPlan($plan);

    expect($grid->start(1))->toEqual(F::date(2026, 1, 5))
        ->and($grid->reviewWeekday())->toBe(F::MONDAY);
});

it('PLAN-008 starts at the start of the day', function () {
    $grid = new PlanWeekGrid(F::date(2026, 1, 5, 15), F::MONDAY);

    expect($grid->start(0))->toEqual(F::date(2026, 1, 5))
        ->and($grid->start(2))->toEqual(F::date(2026, 1, 19));
});

it('PLAN-002 a date before the plan counts back in whole weeks', function () {
    $grid = new PlanWeekGrid(F::date(2026, 1, 1), F::MONDAY);

    expect($grid->weekIndexCountingBack(F::date(2025, 12, 26)))->toBe(0)
        ->and($grid->weekIndexCountingBack(F::date(2025, 12, 25)))->toBe(-1)
        ->and($grid->weekIndexCountingBack(F::date(2025, 12, 18)))->toBe(-2)
        ->and($grid->weekIndexCountingBack(F::date(2026, 1, 5)))->toBe(1);
});

it('PLAN-008 week index containing a date', function () {
    $grid = new PlanWeekGrid(F::date(2026, 1, 1), F::MONDAY);

    expect($grid->weekIndexContaining(F::date(2025, 12, 31)))->toBeNull()
        ->and($grid->weekIndexContaining(F::date(2026, 1, 1)))->toBe(0)
        ->and($grid->weekIndexContaining(F::date(2026, 1, 4)))->toBe(0)
        ->and($grid->weekIndexContaining(F::date(2026, 1, 5)))->toBe(1)
        ->and($grid->weekIndexContaining(F::date(2026, 1, 11)))->toBe(1)
        ->and($grid->weekIndexContaining(F::date(2026, 1, 12)))->toBe(2)
        ->and($grid->weekIndexContaining(F::date(2026, 1, 11, 23, 59)))->toBe(1);
});

it('PLAN-008 is a boundary only on the first day of a week', function () {
    $grid = new PlanWeekGrid(F::date(2026, 1, 1), F::MONDAY);

    expect($grid->isBoundary(F::date(2026, 1, 1)))->toBeTrue()
        ->and($grid->isBoundary(F::date(2026, 1, 2)))->toBeFalse()
        ->and($grid->isBoundary(F::date(2026, 1, 5)))->toBeTrue()
        ->and($grid->isBoundary(F::date(2026, 1, 6)))->toBeFalse()
        ->and($grid->isBoundary(F::date(2026, 1, 12)))->toBeTrue()
        ->and($grid->isBoundary(F::date(2025, 12, 29)))->toBeFalse();
});

it('PLAN-008 changing the review day shortens the transition week', function () {
    // Request Wednesday 2026-01-14 (week 1, Jan 12 to 18): the new day applies from Monday Jan 19.
    // Friday is 4 days after Monday, so week 2 is cut to 4 days.
    $grid = F::grid()->changingReviewDay(F::FRIDAY, F::date(2026, 1, 14));

    expect($grid->reviewWeekday())->toBe(F::FRIDAY)
        ->and($grid->start(2))->toEqual(F::date(2026, 1, 19))
        ->and($grid->start(3))->toEqual(F::date(2026, 1, 23))
        ->and($grid->start(4))->toEqual(F::date(2026, 1, 30))
        ->and($grid->lengthInDays(2))->toBe(4)
        ->and($grid->lengthInDays(3))->toBe(7);
});

it('PLAN-008 changing the review day lengthens the transition week', function () {
    // Wednesday is 2 days after Monday, so week 2 runs 9 days (Jan 19 to 27) instead of 2.
    $grid = F::grid()->changingReviewDay(F::WEDNESDAY, F::date(2026, 1, 14));

    expect($grid->reviewWeekday())->toBe(F::WEDNESDAY)
        ->and($grid->start(2))->toEqual(F::date(2026, 1, 19))
        ->and($grid->start(3))->toEqual(F::date(2026, 1, 28))
        ->and($grid->start(4))->toEqual(F::date(2026, 2, 4))
        ->and($grid->lengthInDays(2))->toBe(9)
        ->and($grid->lengthInDays(3))->toBe(7);
});

it('PLAN-008 changing the review day leaves the week in progress alone', function () {
    $before = F::grid();
    foreach ([F::WEDNESDAY, F::FRIDAY] as $weekday) {
        $after = $before->changingReviewDay($weekday, F::date(2026, 1, 14));
        foreach ([0, 1, 2] as $week) {
            expect($after->start($week))->toEqual($before->start($week), "week {$week}");
        }
        expect($after->lengthInDays(1))->toBe(7)
            ->and($after->weekIndexContaining(F::date(2026, 1, 14)))->toBe(1);
    }
});

it('PLAN-008 changing on a boundary day starts from the next boundary', function () {
    // Requested on Monday Jan 19 itself: that week is already running, so the change starts Jan 26.
    $grid = F::grid()->changingReviewDay(F::FRIDAY, F::date(2026, 1, 19));

    expect($grid->start(2))->toEqual(F::date(2026, 1, 19))
        ->and($grid->start(3))->toEqual(F::date(2026, 1, 26))
        ->and($grid->start(4))->toEqual(F::date(2026, 1, 30));
});

it('PLAN-008 changing twice keeps only the latest change', function () {
    $first = F::grid()->changingReviewDay(F::FRIDAY, F::date(2026, 1, 14));
    $second = $first->changingReviewDay(F::WEDNESDAY, F::date(2026, 1, 15));

    expect($second->reviewWeekday())->toBe(F::WEDNESDAY)
        ->and($second->start(3))->toEqual(F::date(2026, 1, 28));
});

it('PLAN-008 a later change during the transition week applies from the next boundary', function () {
    $first = F::grid()->changingReviewDay(F::FRIDAY, F::date(2026, 1, 14));
    // Week 2 is Jan 19 to 22; a request on Jan 20 takes effect on Friday Jan 23.
    $second = $first->changingReviewDay(F::MONDAY, F::date(2026, 1, 20));

    expect($second->start(2))->toEqual(F::date(2026, 1, 19))
        ->and($second->start(3))->toEqual(F::date(2026, 1, 23))
        ->and($second->start(4))->toEqual(F::date(2026, 2, 2));
});

it('PLAN-008 changing before the plan starts rebases the first week', function () {
    $grid = (new PlanWeekGrid(F::date(2026, 1, 1), F::MONDAY))
        ->changingReviewDay(F::FRIDAY, F::date(2025, 12, 20));

    expect($grid->reviewWeekday())->toBe(F::FRIDAY)
        ->and($grid->start(1))->toEqual(F::date(2026, 1, 2))
        ->and($grid->lengthInDays(0))->toBe(1);
});

it('PLAN-008 weeks stay on local midnight across daylight saving changes', function () {
    $newYork = fn (int $day) => F::date(2026, 3, $day, zone: 'America/New_York');
    $grid = new PlanWeekGrid($newYork(2), F::MONDAY);

    // Clocks go forward on Sunday March 8, inside week 0.
    expect($grid->start(1))->toEqual($newYork(9))
        ->and($grid->start(2))->toEqual($newYork(16))
        ->and($grid->lengthInDays(0))->toBe(7)
        ->and($grid->weekIndexContaining($newYork(9)))->toBe(1);
});

it('PLAN-008 a short first week is a short plan week', function () {
    $grid = new PlanWeekGrid(F::date(2026, 1, 8), F::MONDAY);
    $week = $grid->planWeek(0);

    expect($week->dayCount)->toBe(4)
        ->and($week->days())->toHaveCount(4)
        ->and($week->start)->toEqual(F::date(2026, 1, 8))
        ->and($week->isDeload || $week->isDietBreak || $week->isAway)->toBeFalse()
        ->and($grid->planWeek(1)->dayCount)->toBe(7);
});

it('PLAN-008 a lengthened transition week is a long plan week', function () {
    // Monday to Tuesday is 1 day, so the transition week from Jan 19 is lengthened to 8 days.
    $grid = F::grid()->changingReviewDay(F::TUESDAY, F::date(2026, 1, 14));
    $week = $grid->planWeek(2);

    expect($week->dayCount)->toBe(8)
        ->and($week->days())->toHaveCount(8);
});

it('PLAN-008 a date from another zone is read on the calendar of the plan start', function () {
    $newYork = fn (int $day) => F::date(2026, 3, $day, zone: 'America/New_York');
    $utc = fn (int $day, int $hour) => F::date(2026, 3, $day, $hour);
    $grid = new PlanWeekGrid($newYork(2), F::MONDAY);

    // 02:00 UTC on Monday 9 March is 22:00 on Sunday 8 March in New York; 14:00 UTC is 10:00 on the Monday.
    expect($grid->weekIndexContaining($utc(2, 4)))->toBeNull()
        ->and($grid->weekIndexContaining($utc(9, 2)))->toBe(0)
        ->and($grid->weekIndexContaining($utc(9, 14)))->toBe(1)
        ->and($grid->isBoundary($utc(9, 2)))->toBeFalse()
        ->and($grid->isBoundary($utc(9, 14)))->toBeTrue();

    // Requested on Sunday 15 March local: the change applies from Monday 16 March.
    $sunday = $grid->changingReviewDay(F::FRIDAY, $utc(16, 2));
    expect($sunday->start(2))->toEqual($newYork(16))
        ->and($sunday->start(3))->toEqual($newYork(20));

    // Requested on Monday 16 March local, a boundary day: the change starts from Monday 23 March.
    $monday = $grid->changingReviewDay(F::FRIDAY, $utc(16, 14));
    expect($monday->start(3))->toEqual($newYork(23))
        ->and($monday->start(4))->toEqual($newYork(27));
});
