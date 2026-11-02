<?php

// The same tests as rp-clone's ReviewTimingTests.swift (pin 2aa1edeb), one for one.

use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Review\ReviewStatus;
use App\Domain\Review\StoredPlanWeek;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\Review\ReviewTimingFixtures as F;

// REV-001

it('REV-001 available on the review day once the week that ended has two weigh-ins', function () {
    $status = F::status(12, [6, 9]);
    expect($status->isAvailable())->toBeTrue();
    $period = $status->period();
    expect($period->reviewed->start)->toEqual(F::date(5))
        ->and($period->reviewedIndex)->toBe(0)
        ->and($period->next->start)->toEqual(F::date(12))
        ->and($period->nextIndex)->toBe(1);
});

it('REV-001 not with only one weigh-in', function () {
    expect(F::status(12, [6]))->toEqual(ReviewStatus::unavailable());
});

it('REV-001 weigh-ins outside the week that ended do not count', function () {
    // 4 January is the week before; 12 January is the first day of the new week.
    expect(F::status(12, [4, 12, 6]))->toEqual(ReviewStatus::unavailable());
    expect(F::status(12, [5, 11]))->not->toEqual(ReviewStatus::unavailable(), 'the first and the last day both count');
});

it('REV-001 nothing to review before the first week has ended', function () {
    expect(F::status(8, [6, 7]))->toEqual(ReviewStatus::unavailable());
});

it('REV-001 nothing before the plan starts', function () {
    expect(F::status(2, [6, 7]))->toEqual(ReviewStatus::unavailable());
});

it('REV-001 the week is done once its row is stamped', function () {
    $stamped = new StoredPlanWeek(start: F::date(5), reviewedAt: F::date(12)->setTime(9, 0));
    $status = F::status(12, [6, 9], [$stamped]);
    expect($status->isDone())->toBeTrue()
        ->and($status->period()->reviewed->start)->toEqual(F::date(5))
        ->and($status->isAvailable())->toBeFalse();
});

it('REV-001 a row without a stamp is not done', function () {
    $status = F::status(12, [6, 9], [new StoredPlanWeek(start: F::date(5))]);
    expect($status->isAvailable())->toBeTrue();
});

// REV-002

it('REV-002 the card stays after the third day', function () {
    // Day 1 is 12 January; 17 January is day 6.
    foreach ([12, 14, 15, 17, 18] as $today) {
        expect(F::status($today, [6, 9])->isAvailable())->toBeTrue('day '.($today - 11));
    }
});

it('REV-002 a new week replaces the one never reviewed', function () {
    // On 19 January the week that ended is 12 to 18 January; week 0 is no longer offered.
    expect(F::status(19, [6, 9]))->toEqual(ReviewStatus::unavailable());
    $status = F::status(19, [6, 9, 13, 15]);
    expect($status->isAvailable())->toBeTrue('the newest finished week is reviewable')
        ->and($status->period()->reviewed->start)->toEqual(F::date(12))
        ->and($status->period()->next->start)->toEqual(F::date(19));
});

// SCH-061

it('SCH-061 an away week is reviewable without weigh-ins', function () {
    $away = new StoredPlanWeek(start: F::date(5), isAway: true);
    $status = F::status(12, [], [$away]);
    expect($status->isAvailable())->toBeTrue()
        ->and($status->period()->reviewed->isAway)->toBeTrue();
});

// MESO-044

it('MESO-044 the stored flags of both weeks carry into the period', function () {
    $stored = [
        new StoredPlanWeek(start: F::date(5), isDeload: true),
        new StoredPlanWeek(start: F::date(12), isDietBreak: true),
    ];
    $status = F::status(12, [6, 9], $stored);
    expect($status->isAvailable())->toBeTrue();
    $period = $status->period();
    expect($period->reviewed->isDeload)->toBeTrue()
        ->and($period->next->isDietBreak)->toBeTrue()
        ->and($period->next->isDeload)->toBeFalse();
});

// PLAN-008

it('PLAN-008 a short first week is reviewed over its own days', function () {
    // The plan starts on Thursday 8 January with Monday as its review day: a 4-day first week.
    $short = new PlanWeekGrid(F::date(8), PlanFixtures::MONDAY);
    $status = F::status(12, [9, 11], grid: $short);
    expect($status->isAvailable())->toBeTrue()
        ->and($status->period()->reviewed->dayCount)->toBe(4);
    expect(F::status(12, [6, 7], grid: $short))->toEqual(ReviewStatus::unavailable(), 'days before the plan are not in the week');
});
