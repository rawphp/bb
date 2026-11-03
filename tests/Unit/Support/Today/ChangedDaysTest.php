<?php

use App\Support\Today\ChangedDays;
use App\Support\Today\TodaySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// SCH-023, CAP-010: a write that moves later weeks names every day the client can browse to, which is the whole week
// strip around the last day with a target (TodaySnapshot::insidePlan opens any day of it). Plan weeks run Monday.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('SCH-023 CAP-010 the range ends on the last day of the strip week of the last target', function () {
    Carbon::setTestNow('2026-09-29 08:00:00');
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-10-21');

    $days = ChangedDays::from(TodaySnapshot::loadWeek($user), new DateTimeImmutable('2026-10-19'));

    expect($days)->toBe(['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25']);
});

it('SCH-023 CAP-010 the range ends on the plan week in progress when the last target is earlier', function () {
    Carbon::setTestNow('2026-09-29 08:00:00');
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-09-29');

    $days = ChangedDays::from(TodaySnapshot::loadWeek($user), new DateTimeImmutable('2026-09-28'));

    expect(end($days))->toBe('2026-10-04');
});

it('SCH-023 CAP-010 a change reaching back to an older plan week starts there', function () {
    Carbon::setTestNow('2026-09-29 08:00:00');
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);

    $days = ChangedDays::fromCurrentWeek(TodaySnapshot::loadWeek($user), new DateTimeImmutable('2026-09-09'));

    expect($days[0])->toBe('2026-09-07');
});
