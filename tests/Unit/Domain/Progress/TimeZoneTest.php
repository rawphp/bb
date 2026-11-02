<?php

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\WeighIn;
use App\Domain\Model\WeightUnit;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\DayAdherence;
use App\Domain\Progress\Days;
use App\Domain\Progress\DayTotals;
use App\Domain\Progress\MesocycleHistory;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Progress\ProgressMesocycle;
use App\Domain\Progress\ProgressScope;
use App\Domain\Progress\ProgressShare;
use App\Domain\Progress\ProgressSummary;
use App\Domain\Weight\TrendWeight;

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

it('ANA-007 a mesocycle across the autumn daylight saving change ends at local midnight 28 days on', function () {
    // New York falls back on 1 November 2026, so 12 October plus 28 days is 28 x 24 + 1 hours later.
    $newYork = new DateTimeZone('America/New_York');
    $meso = new ProgressMesocycle(id: 'm1', phaseId: null, name: 'Meso m1', start: at('2026-10-12', 'America/New_York'), weeks: 4);

    expect($meso->end($newYork))->toEqual(at('2026-11-09', 'America/New_York'))
        // On 8 November it is still running; on 9 November it has ended.
        ->and(MesocycleHistory::entries([$meso], [], [], at('2026-11-08 23:59', 'America/New_York'), $newYork))->toBe([])
        ->and(MesocycleHistory::entries([$meso], [], [], at('2026-11-09 00:00', 'America/New_York'), $newYork))->toHaveCount(1);
});

it('ANA-002 the current phase is the one running on the user\'s day, not UTC\'s', function () {
    // 23:30 UTC on 10 October is 11 October in Sydney, the day a muscle gain phase follows the cut.
    $sydney = new DateTimeZone('Australia/Sydney');
    $today = at('2026-10-10 23:30', 'UTC');
    $phases = [
        new Phase('cut', PhaseType::FatLoss, at('2026-09-01', 'Australia/Sydney'), at('2026-10-11', 'Australia/Sydney'), -0.5),
        new Phase('bulk', PhaseType::MuscleGain, at('2026-10-11', 'Australia/Sydney'), null, 0.25),
    ];
    $weighIns = array_map(
        fn (int $i) => new WeighIn("w{$i}", at('2026-10-01 07:00', 'Australia/Sydney')->modify("+{$i} days"), 80),
        range(0, 10),
    );
    $trend = new TrendWeight($weighIns, $sydney);
    $summary = ProgressSummary::make(ProgressScope::CurrentPhase, $trend, $phases, at('2026-09-01', 'Australia/Sydney'), $today, $sydney);

    expect(ProgressScope::CurrentPhase->start($phases, at('2026-09-01', 'Australia/Sydney'), $today, $sydney))
        ->toEqual(at('2026-10-11', 'Australia/Sydney'))
        ->and($summary?->phaseType)->toBe(PhaseType::MuscleGain)
        ->and($summary?->startDay)->toEqual(at('2026-10-11', 'Australia/Sydney'))
        ->and($summary?->currentDay)->toEqual(at('2026-10-11', 'Australia/Sydney'));
});
