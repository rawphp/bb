<?php

use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\PhaseSwitch;
use App\Domain\Plan\PhaseWrite;
use App\Domain\Plan\PlanWeekGrid;

// SAFE-017: a re-check that changes the phase does it from the first day of the plan week holding today. Ported from
// rp-clone's PhaseSwitchTests. The plan starts Monday 7 Sep 2026 and reviews on Mondays: Wednesday 14 Oct is in the
// week of 12 Oct.

const SWITCH_CURRENT = '11111111-1111-4111-8111-111111111111';
const SWITCH_NEW = '22222222-2222-4222-8222-222222222222';

function switchDay(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

function switchGrid(string $start = '2026-09-07'): PlanWeekGrid
{
    return new PlanWeekGrid(switchDay($start), 2);
}

function switchPhase(string $id, PhaseType $type, string $start, ?string $end, float $rate, ?string $event = null): Phase
{
    return new Phase($id, $type, switchDay($start), $end === null ? null : switchDay($end), $rate, $event === null ? null : switchDay($event));
}

/** @param  list<PhaseWrite>  $writes */
function switchRows(array $writes): array
{
    return array_map(fn (PhaseWrite $write) => [
        'id' => $write->phase->id,
        'exists' => $write->exists,
        'type' => $write->phase->type,
        'start' => $write->phase->start->format('Y-m-d'),
        'end' => $write->phase->end?->format('Y-m-d'),
        'rate' => $write->phase->targetRatePercentPerWeek,
        'event' => $write->phase->eventDate?->format('Y-m-d'),
    ], $writes);
}

it('SAFE-017 the switch day is the first day of the plan week holding today', function () {
    expect(PhaseSwitch::day(switchGrid(), switchDay('2026-10-14'))->format('Y-m-d'))->toBe('2026-10-12');
});

it('SAFE-017 on the week\'s first day the switch is today', function () {
    expect(PhaseSwitch::day(switchGrid(), switchDay('2026-10-12'))->format('Y-m-d'))->toBe('2026-10-12');
});

it('SAFE-017 a plan that has not started switches from its first week', function () {
    expect(PhaseSwitch::day(switchGrid('2026-10-19'), switchDay('2026-10-14'))->format('Y-m-d'))->toBe('2026-10-19');
});

it('SAFE-017 the covering phase ends on the switch day and the new phase starts there', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::Maintenance, '2026-09-07', '2026-11-02', 0.0)];

    $writes = PhaseSwitch::writes($phases, switchDay('2026-10-12'), PhaseType::MuscleGain, 0.25, switchDay('2027-01-04'), SWITCH_NEW);

    expect(switchRows($writes))->toBe([
        ['id' => SWITCH_CURRENT, 'exists' => true, 'type' => PhaseType::Maintenance, 'start' => '2026-09-07', 'end' => '2026-10-12', 'rate' => 0.0, 'event' => null],
        ['id' => SWITCH_NEW, 'exists' => false, 'type' => PhaseType::MuscleGain, 'start' => '2026-10-12', 'end' => '2027-01-04', 'rate' => 0.25, 'event' => null],
    ]);
});

it('SAFE-017 a phase starting on the switch day is updated in place', function () {
    $phases = [
        switchPhase('33333333-3333-4333-8333-333333333333', PhaseType::FatLoss, '2026-09-07', '2026-10-12', -0.5),
        switchPhase(SWITCH_CURRENT, PhaseType::Maintenance, '2026-10-12', '2026-11-09', 0.0),
    ];

    $writes = PhaseSwitch::writes($phases, switchDay('2026-10-12'), PhaseType::Maintenance, 0.0, switchDay('2026-12-07'), SWITCH_NEW);

    expect(switchRows($writes))->toBe([
        ['id' => SWITCH_CURRENT, 'exists' => true, 'type' => PhaseType::Maintenance, 'start' => '2026-10-12', 'end' => '2026-12-07', 'rate' => 0.0, 'event' => null],
    ]);
});

it('SAFE-017 an open-ended phase is closed on the switch day', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::FatLoss, '2026-09-07', null, -0.5)];

    $writes = PhaseSwitch::writes($phases, switchDay('2026-10-12'), PhaseType::Maintenance, 0.0, switchDay('2026-11-09'), SWITCH_NEW);

    expect($writes)->toHaveCount(2)
        ->and($writes[0]->phase->end?->format('Y-m-d'))->toBe('2026-10-12');
});

it('SAFE-017 with no covering phase only the new phase is added', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::FatLoss, '2026-09-07', '2026-10-05', -0.5)];

    $writes = PhaseSwitch::writes($phases, switchDay('2026-10-12'), PhaseType::Maintenance, 0.0, switchDay('2026-11-09'), SWITCH_NEW);

    expect(array_map(fn (PhaseWrite $write) => $write->exists, $writes))->toBe([false])
        ->and($writes[0]->phase->start->format('Y-m-d'))->toBe('2026-10-12');
});

it('SAFE-017 the phase covering the switch day is the one used', function () {
    $phases = [
        switchPhase('33333333-3333-4333-8333-333333333333', PhaseType::FatLoss, '2026-09-07', '2026-10-05', -0.5),
        switchPhase(SWITCH_CURRENT, PhaseType::MiniCut, '2026-10-05', '2026-11-02', -1.0),
    ];

    expect(PhaseSwitch::covering(switchDay('2026-10-12'), $phases)?->id)->toBe(SWITCH_CURRENT)
        ->and(PhaseSwitch::covering(switchDay('2026-11-02'), $phases))->toBeNull();
});

it('SAFE-017 PH-043 an event after the switch day moves to the new phase', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::FatLoss, '2026-09-07', '2026-12-07', -0.5, event: '2026-11-20')];

    $writes = PhaseSwitch::writes($phases, switchDay('2026-10-12'), PhaseType::FatLoss, 0.0, switchDay('2026-11-16'), SWITCH_NEW);

    expect(array_column(switchRows($writes), 'event'))->toBe([null, '2026-11-20']);
});

it('SAFE-017 an event before the switch day stays with the phase it ended', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::FatLoss, '2026-09-07', '2026-12-07', -0.5, event: '2026-10-01')];

    $writes = PhaseSwitch::writes($phases, switchDay('2026-10-12'), PhaseType::FatLoss, 0.0, switchDay('2026-11-16'), SWITCH_NEW);

    expect(array_column(switchRows($writes), 'event'))->toBe(['2026-10-01', null]);
});

it('SAFE-017 PLAN-007 moving to training only keeps the type, drops the rate and runs one default mesocycle', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::FatLoss, '2026-09-07', '2026-12-07', -0.5)];

    $writes = PhaseSwitch::toTrainingOnly($phases, switchGrid(), switchDay('2026-10-14'), ExperienceLevel::Intermediate, SWITCH_NEW);

    // TBL-020: an intermediate's default mesocycle is 5 weeks.
    expect(switchRows($writes))->toBe([
        ['id' => SWITCH_CURRENT, 'exists' => true, 'type' => PhaseType::FatLoss, 'start' => '2026-09-07', 'end' => '2026-10-12', 'rate' => -0.5, 'event' => null],
        ['id' => SWITCH_NEW, 'exists' => false, 'type' => PhaseType::FatLoss, 'start' => '2026-10-12', 'end' => '2026-11-16', 'rate' => 0.0, 'event' => null],
    ]);
});

it('SAFE-017 PLAN-007 with no phase covering the switch day, training only runs Maintenance', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::FatLoss, '2026-09-07', '2026-10-05', -0.5)];

    $writes = PhaseSwitch::toTrainingOnly($phases, switchGrid(), switchDay('2026-10-14'), ExperienceLevel::Intermediate, SWITCH_NEW);

    expect(switchRows($writes))->toBe([
        ['id' => SWITCH_NEW, 'exists' => false, 'type' => PhaseType::Maintenance, 'start' => '2026-10-12', 'end' => '2026-11-16', 'rate' => 0.0, 'event' => null],
    ]);
});

it('SAFE-017 PLAN-007 before the plan starts, training only starts on its first day', function () {
    $phases = [switchPhase(SWITCH_CURRENT, PhaseType::MuscleGain, '2026-10-19', '2027-01-11', 0.25)];

    $writes = PhaseSwitch::toTrainingOnly($phases, switchGrid('2026-10-19'), switchDay('2026-10-14'), ExperienceLevel::Intermediate, SWITCH_NEW);

    expect(switchRows($writes))->toBe([
        ['id' => SWITCH_CURRENT, 'exists' => true, 'type' => PhaseType::MuscleGain, 'start' => '2026-10-19', 'end' => '2026-11-23', 'rate' => 0.0, 'event' => null],
    ]);
});
