<?php

use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Workout\FeedbackSheets;
use App\Domain\Workout\OtherSession;
use App\Domain\Workout\SessionExercise;

// The Feedback sheets of one session (FB-001 to FB-009, WO-040), from rp-clone's WorkoutModel+Feedback and its
// WorkoutFeedbackTests. Exercise 1 is Bench Press (chest), 2 Incline Press (chest), 3 Barbell Row (back).

const SHEET_START = '2026-10-12 17:30:00';

function sheetExercise(int $id, MuscleGroup $muscle, bool $done = true, int $sets = 3): SessionExercise
{
    return new SessionExercise($id, ['', 'Bench Press', 'Incline Press', 'Barbell Row'][$id] ?? "Exercise {$id}", $muscle, $sets, $done);
}

/** @param list<OtherSession> $others */
function sheetsOf(array $exercises, array $others = [], array $answers = [], array $pain = [], bool $deload = false, bool $skipped = false): FeedbackSheets
{
    return new FeedbackSheets($exercises, new DateTimeImmutable(SHEET_START), $deload, $skipped, $others, $answers, $pain);
}

function sheetEarlier(string $at, array $working = [MuscleGroup::Chest], ?array $any = null, bool $skipped = false, bool $laterInMesocycle = false): OtherSession
{
    return new OtherSession(new DateTimeImmutable($at), $skipped, $laterInMesocycle, $working, $any ?? $working);
}

function sheetChestDone(): array
{
    return [
        sheetExercise(1, MuscleGroup::Chest),
        sheetExercise(2, MuscleGroup::Chest),
        sheetExercise(3, MuscleGroup::Back, done: false),
    ];
}

it('FB-001 a muscle is due once every set of its last exercise is logged or skipped', function () {
    expect(sheetsOf(sheetChestDone())->due())->toBe([MuscleGroup::Chest]);
});

it('FB-001 a muscle is not due while its last exercise has a set left', function () {
    $exercises = [sheetExercise(1, MuscleGroup::Chest), sheetExercise(2, MuscleGroup::Chest, done: false)];

    expect(sheetsOf($exercises)->due())->toBe([]);
});

it('FB-001 a muscle is due after its last exercise in the current order, and the sheets come in that order', function () {
    $exercises = [sheetExercise(3, MuscleGroup::Back), sheetExercise(1, MuscleGroup::Chest), sheetExercise(2, MuscleGroup::Chest)];

    expect(sheetsOf($exercises)->due())->toBe([MuscleGroup::Back, MuscleGroup::Chest]);
});

it('FB-001 a muscle\'s last exercise is the last one that has sets', function () {
    $exercises = [sheetExercise(1, MuscleGroup::Chest), sheetExercise(2, MuscleGroup::Chest, done: false, sets: 0)];

    expect(sheetsOf($exercises)->due())->toBe([MuscleGroup::Chest]);
});

it('FB-002 asks joint pain once per exercise of the muscle that has sets', function () {
    $exercises = [...sheetChestDone(), sheetExercise(1, MuscleGroup::Chest), sheetExercise(4, MuscleGroup::Chest, sets: 0)];

    expect(array_map(fn (SessionExercise $e) => $e->exerciseId, sheetsOf($exercises)->asked(MuscleGroup::Chest)))->toBe([1, 2]);
});

it('FB-007 asks soreness when the muscle trained within two weeks', function () {
    expect(sheetsOf(sheetChestDone(), [sheetEarlier('2026-10-08 17:30:00')])->asksSoreness(MuscleGroup::Chest))->toBeTrue()
        ->and(sheetsOf(sheetChestDone(), [sheetEarlier('2026-09-28 17:30:00')])->asksSoreness(MuscleGroup::Chest))->toBeTrue();
});

it('FB-007 skips soreness the first time the muscle is trained in the app', function () {
    $others = [sheetEarlier('2026-10-08 17:30:00', working: [MuscleGroup::Back])];

    expect(sheetsOf(sheetChestDone(), $others)->asksSoreness(MuscleGroup::Chest))->toBeFalse();
});

it('FB-007 skips soreness when the muscle was last trained more than 14 days ago', function () {
    expect(sheetsOf(sheetChestDone(), [sheetEarlier('2026-09-28 17:29:00')])->asksSoreness(MuscleGroup::Chest))->toBeFalse();
});

it('FB-007 counts only earlier sessions that were done and had a logged set of the muscle', function () {
    $others = [
        sheetEarlier('2026-10-08 17:30:00', skipped: true),
        sheetEarlier('2026-10-08 17:30:00', working: [], any: [MuscleGroup::Chest]),
        sheetEarlier('2026-10-15 17:30:00'),
    ];

    expect(sheetsOf(sheetChestDone(), $others)->asksSoreness(MuscleGroup::Chest))->toBeFalse();
});

it('FB-008 asks nothing in a deload week, and nothing of a skipped session', function () {
    expect(sheetsOf(sheetChestDone(), deload: true)->due())->toBe([])
        ->and(sheetsOf(sheetChestDone(), deload: true)->pending())->toBe([])
        ->and(sheetsOf(sheetChestDone(), skipped: true)->due())->toBe([]);
});

it('FB-009 locks a muscle once a session started later has trained it', function () {
    expect(sheetsOf(sheetChestDone(), [sheetEarlier('2026-10-15 17:30:00')])->isLocked(MuscleGroup::Chest))->toBeTrue()
        ->and(sheetsOf(sheetChestDone(), [sheetEarlier('2026-10-15 17:30:00', working: [MuscleGroup::Back])])->isLocked(MuscleGroup::Chest))->toBeFalse()
        ->and(sheetsOf(sheetChestDone(), [sheetEarlier('2026-10-08 17:30:00')])->isLocked(MuscleGroup::Chest))->toBeFalse();
});

it('FB-009 a later session of the mesocycle locks it even when its clock time is earlier', function () {
    $other = sheetEarlier('2026-10-01 09:00:00', working: [], any: [MuscleGroup::Chest], laterInMesocycle: true);

    expect(sheetsOf(sheetChestDone(), [$other])->isLocked(MuscleGroup::Chest))->toBeTrue();
});

it('FB-009 a skipped later session does not lock the muscle', function () {
    expect(sheetsOf(sheetChestDone(), [sheetEarlier('2026-10-15 17:30:00', skipped: true)])->isLocked(MuscleGroup::Chest))->toBeFalse();
});

it('WO-040 a due sheet blocks Finish until joint pain, pump and volume are answered', function () {
    $answers = ['chest' => new FeedbackAnswers(pump: Pump::Low, volume: VolumeFeedback::JustRight)];

    expect(sheetsOf(sheetChestDone())->pending())->toBe([MuscleGroup::Chest])
        ->and(sheetsOf(sheetChestDone(), answers: $answers, pain: [1 => JointPain::None])->pending())->toBe([MuscleGroup::Chest])
        ->and(sheetsOf(sheetChestDone(), answers: $answers, pain: [1 => JointPain::None, 2 => JointPain::Low])->pending())->toBe([]);
});

it('FB-007 a muscle still needs its soreness answer when it is asked', function () {
    $others = [sheetEarlier('2026-10-08 17:30:00')];
    $pain = [1 => JointPain::None, 2 => JointPain::None];
    $without = ['chest' => new FeedbackAnswers(pump: Pump::Low, volume: VolumeFeedback::JustRight)];
    $with = ['chest' => new FeedbackAnswers(soreness: Soreness::StillSore, pump: Pump::Low, volume: VolumeFeedback::JustRight)];

    expect(sheetsOf(sheetChestDone(), $others, $without, $pain)->isAnswered(MuscleGroup::Chest))->toBeFalse()
        ->and(sheetsOf(sheetChestDone(), $others, $with, $pain)->isAnswered(MuscleGroup::Chest))->toBeTrue();
});
