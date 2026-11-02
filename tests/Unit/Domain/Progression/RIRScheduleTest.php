<?php

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Domain\Progression\RIRSchedule;

/** @return list<?int> */
function rirScheduleOf(int $mesocycleWeeks): array
{
    return array_map(fn (int $week) => RIRSchedule::targetRIR($week, $mesocycleWeeks), range(1, $mesocycleWeeks));
}

/**
 * @param  list<Session>  $sessions
 * @param  array<string, Priority>  $priorities
 */
function rirMesocycle(int $weeks, PhaseType $phase = PhaseType::MuscleGain, array $sessions = [], array $priorities = []): Mesocycle
{
    return new Mesocycle('meso', $phase, $weeks, $sessions, $priorities);
}

/** @return list<?int> */
function rirOfMesocycle(Mesocycle $mesocycle): array
{
    return array_map(fn (int $week) => RIRSchedule::targetRIR($week, $mesocycle->weeks), range(1, $mesocycle->weeks));
}

it('PROG-030 starts at 3 in week 1 for every length', function (int $weeks) {
    expect(RIRSchedule::targetRIR(1, $weeks))->toBe(3);
})->with(range(3, 8));

it('PROG-030 reaches 0 in the last training week, except resensitization', function () {
    expect(RIRSchedule::targetRIR(2, 3))->toBe(2);
    foreach (range(4, 8) as $weeks) {
        expect(RIRSchedule::targetRIR($weeks - 1, $weeks))->toBe(0);
    }
});

it('PROG-031 the schedule depends on the length only', function () {
    $a = rirMesocycle(6);
    $b = rirMesocycle(
        6, PhaseType::FatLoss,
        [new Session('push', 'Push', [new ExerciseSlot('bench', 'Bench', MuscleGroup::Chest, 3)])],
        [MuscleGroup::Chest->value => Priority::Emphasize],
    );

    expect(rirOfMesocycle($a))->toBe(rirOfMesocycle($b))
        ->and(rirOfMesocycle($a))->toBe([3, 2, 2, 1, 0, null]);
});

it('PROG-031 changing the length recomputes the schedule', function () {
    expect(RIRSchedule::targetRIR(3, rirMesocycle(6)->weeks))->toBe(2)
        ->and(RIRSchedule::targetRIR(3, rirMesocycle(4)->weeks))->toBe(0);
});

it('PROG-040 the last week is the deload, with no target RIR', function (int $weeks) {
    expect(RIRSchedule::isDeload($weeks, $weeks))->toBeTrue()
        ->and(RIRSchedule::targetRIR($weeks, $weeks))->toBeNull();
    foreach (range(1, $weeks - 1) as $week) {
        expect(RIRSchedule::isDeload($week, $weeks))->toBeFalse()
            ->and(RIRSchedule::targetRIR($week, $weeks))->not->toBeNull();
    }
})->with(range(3, 8));

it('PROG-040 a mesocycle\'s weeks match the schedule', function () {
    $mesocycle = rirMesocycle(5);
    expect(array_map(fn (int $week) => RIRSchedule::isDeload($week, $mesocycle->weeks), range(1, 5)))
        ->toBe([false, false, false, false, true]);
});

it('PROG-040 weeks out of range are not deloads and have no RIR', function (int $week) {
    expect(RIRSchedule::isDeload($week, 5))->toBeFalse()
        ->and(RIRSchedule::targetRIR($week, 5))->toBeNull();
})->with([-1, 0, 6, 7]);

it('PROG-030 a length with no table row has no RIR', function () {
    expect(RIRSchedule::targetRIR(1, 2))->toBeNull()
        ->and(RIRSchedule::targetRIR(1, 9))->toBeNull();
});

it('REV-011 shortening a 7-week mesocycle to 5 recomputes the schedule', function () {
    $seven = rirMesocycle(7);
    expect(rirOfMesocycle($seven))->toBe([3, 3, 2, 2, 1, 0, null])
        ->and(RIRSchedule::targetRIR(3, $seven->weeks))->toBe(2)
        ->and(RIRSchedule::targetRIR(4, $seven->weeks))->toBe(2);

    $five = rirMesocycle(5);
    expect(rirOfMesocycle($five))->toBe([3, 2, 1, 0, null])
        ->and(RIRSchedule::targetRIR(3, $five->weeks))->toBe(1)
        ->and(RIRSchedule::targetRIR(4, $five->weeks))->toBe(0)
        ->and(RIRSchedule::isDeload(5, $five->weeks))->toBeTrue();
});

it('REV-011 moving the deload to week 4 of a 6-week mesocycle', function () {
    expect(RIRSchedule::isDeload(4, rirMesocycle(6)->weeks))->toBeFalse();
    $four = rirMesocycle(4);
    expect(RIRSchedule::isDeload(4, $four->weeks))->toBeTrue()
        ->and(array_map(fn (int $week) => RIRSchedule::targetRIR($week, $four->weeks), [1, 2, 3]))->toBe([3, 1, 0]);
});

it('TBL-023 target RIR by training weeks', function (int $mesocycleWeeks, array $schedule) {
    expect(rirScheduleOf($mesocycleWeeks))->toBe($schedule);
})->with([
    'two training weeks is resensitization' => [3, [3, 2, null]],
    'three training weeks' => [4, [3, 1, 0, null]],
    'four training weeks' => [5, [3, 2, 1, 0, null]],
    'five training weeks' => [6, [3, 2, 2, 1, 0, null]],
    'six training weeks' => [7, [3, 3, 2, 2, 1, 0, null]],
    'seven training weeks' => [8, [3, 3, 2, 2, 1, 1, 0, null]],
    'a length with no row has no RIR anywhere' => [2, [null, null]],
]);
