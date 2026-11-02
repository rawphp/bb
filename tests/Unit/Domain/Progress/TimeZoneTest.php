<?php

use App\Domain\Model\PhaseType;
use App\Domain\Model\WeightUnit;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\DayAdherence;
use App\Domain\Progress\Days;
use App\Domain\Progress\DayTotals;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Progress\ProgressShare;
use App\Domain\Progress\ProgressSummary;

// The days these rules count are calendar days in the user's zone, not UTC days, and survive daylight saving.

function at(string $text, string $zone): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone($zone));
}

it('ANA-011 today is the user\'s day: late on 10 October UTC is already 11 October in Sydney', function () {
    $sydney = new DateTimeZone('Australia/Sydney');
    // 23:30 UTC on Saturday 10 October is 10:30 on Sunday 11 October in Sydney (UTC+11).
    $today = at('2026-10-10 23:30', 'UTC');
    $target = new DayTotals(2000, 150);

    // 10 October has ended in Sydney, so it gets its dot; in UTC it would still be today.
    expect(Adherence::status(at('2026-10-10', 'Australia/Sydney'), $today, at('2026-08-04', 'Australia/Sydney'), $target, $target, $sydney))
        ->toBe(DayAdherence::OnTrack)
        ->and(Adherence::status(at('2026-10-10', 'UTC'), $today, at('2026-08-04', 'UTC'), $target, $target, new DateTimeZone('UTC')))
        ->toBe(DayAdherence::NoDot)
        // An instant on 10 October UTC that is 11 October in Sydney is today there: no dot.
        ->and(Adherence::status(at('2026-10-10 13:30', 'UTC'), $today, at('2026-08-04', 'Australia/Sydney'), $target, $target, $sydney))
        ->toBe(DayAdherence::NoDot)
        // The plan start is a Sydney day too: 4 August 00:30 Sydney is 3 August UTC, and still in the plan.
        ->and(Adherence::status(at('2026-08-03 14:30', 'UTC'), $today, at('2026-08-04', 'Australia/Sydney'), $target, $target, $sydney))
        ->toBe(DayAdherence::OnTrack);
});

it('ANA-003 the plan week follows the user\'s weekday, not UTC\'s', function () {
    $sydney = new DateTimeZone('Australia/Sydney');
    $today = at('2026-10-10 23:30', 'UTC');

    // Sunday in Sydney, Saturday in UTC.
    expect(PlanWeekDays::isoWeekday($today, $sydney))->toBe(7)
        ->and(PlanWeekDays::isoWeekday($today, new DateTimeZone('UTC')))->toBe(6);

    // Review on Sunday: in Sydney the week starts today, 11 October, at local midnight.
    $week = PlanWeekDays::currentWeek($today, 7, $sydney);
    expect($week[0])->toEqual(at('2026-10-11', 'Australia/Sydney'))
        ->and($week[6])->toEqual(at('2026-10-17', 'Australia/Sydney'))
        ->and(PlanWeekDays::currentWeek($today, 7, new DateTimeZone('UTC'))[0])->toEqual(at('2026-10-04', 'UTC'));
});

it('ANA-003 a plan week across a daylight saving change keeps seven local midnights', function () {
    // New York leaves daylight saving on Sunday 1 November 2026: that day has 25 hours.
    $newYork = new DateTimeZone('America/New_York');
    $week = PlanWeekDays::currentWeek(at('2026-11-04 12:00', 'America/New_York'), 6, $newYork);

    expect(array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d H:i'), $week))->toBe([
        '2026-10-31 00:00', '2026-11-01 00:00', '2026-11-02 00:00', '2026-11-03 00:00',
        '2026-11-04 00:00', '2026-11-05 00:00', '2026-11-06 00:00',
    ]);
});

it('ANA-001 whole days and the start of a day are counted in the user\'s zone across daylight saving', function () {
    $newYork = new DateTimeZone('America/New_York');
    // 8 March 2026 has 23 hours in New York; 1 November 2026 has 25.
    expect(Days::whole(at('2026-03-07', 'America/New_York'), at('2026-03-09', 'America/New_York'), $newYork))->toBe(2)
        ->and(Days::whole(at('2026-10-31', 'America/New_York'), at('2026-11-02', 'America/New_York'), $newYork))->toBe(2)
        ->and(Days::whole(at('2026-03-09', 'America/New_York'), at('2026-03-07', 'America/New_York'), $newYork))->toBe(-2)
        // Instants are taken at the start of their local day: 03:30 UTC on 9 March is 8 March in New York.
        ->and(Days::whole(at('2026-03-07 23:00', 'America/New_York'), at('2026-03-09 03:30', 'UTC'), $newYork))->toBe(1)
        ->and(Days::startOf(at('2026-03-09 03:30', 'UTC'), $newYork))->toEqual(at('2026-03-08', 'America/New_York'));
});

it('ANA-010 the share text counts a span with a 23-hour day as whole weeks', function () {
    // 2 to 16 March 2026 in New York is 14 days but 335 hours (8 March has 23).
    $summary = new ProgressSummary(
        startKg: 82, currentKg: 81, goalKg: null, phaseType: PhaseType::FatLoss,
        startDay: at('2026-03-02', 'America/New_York'), currentDay: at('2026-03-16', 'America/New_York'),
    );
    expect(ProgressShare::text($summary, WeightUnit::Kg))->toBe('Fat loss: 82.0 kg to 81.0 kg (-1.0 kg) over 2 weeks');
});
