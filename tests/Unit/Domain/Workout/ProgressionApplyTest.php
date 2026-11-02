<?php

use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Workout\ProgressionApply;
use App\Domain\Workout\ProgressionInput;

// PROG-010 to PROG-019, PROG-050, PROG-051, UI-010: the session as it ran and its feedback, turned into the day's
// slot counts and the toast lines. From rp-clone's ProgressionApplyTests.
//
// A gain phase (an intermediate lifter may add 2 sets per session per week, chest MRV 22 + 2). Day 1: Bench Press (12)
// 3 and Incline Press (13) 3 for chest, Barbell Row (25) 3 for back, Curl (40) 2 for biceps. Day 2: Cable Fly (14)
// for chest and Squat (30) 3. Chest is Emphasize, back is Grow, biceps is Maintain.

function applySlot(string $id, int $exercise, string $muscle, int $sets): array
{
    return ['id' => $id, 'exercise_id' => $exercise, 'muscle_group' => $muscle, 'sets' => $sets];
}

function applyDays(array $chest = [3, 3], int $fly = 3, int $row = 3, ?array $extra = null): array
{
    $day1 = [
        applySlot('bench', 12, 'chest', $chest[0]), applySlot('incline', 13, 'chest', $chest[1]),
        applySlot('row', 25, 'back', $row), applySlot('curl', 40, 'biceps', 2),
    ];
    if ($extra !== null) {
        $day1[] = $extra;
    }

    return [
        ['id' => 'day-1', 'label' => 'Upper A', 'weekday' => 1, 'slots' => $day1],
        ['id' => 'day-2', 'label' => 'Lower A', 'weekday' => 4, 'slots' => [applySlot('fly', 14, 'chest', $fly), applySlot('squat', 30, 'quads', 3)]],
    ];
}

/** The sets the session ran, by exercise: the stored counts unless the test says otherwise. */
function applyRan(?int $bench = 3, ?int $incline = 3, ?int $row = 3, ?int $curl = 2, array $others = []): array
{
    return array_replace(array_filter([12 => $bench, 13 => $incline, 25 => $row, 40 => $curl], fn ($n) => $n !== null), $others);
}

function applyInput(array $days, array $sets, array $muscles = [], array $pain = [], array $held = []): ProgressionInput
{
    return new ProgressionInput(
        days: $days, dayIndex: 0, phase: PhaseType::MuscleGain, isResensitization: false, weeks: 5,
        priorities: ['chest' => Priority::Emphasize, 'back' => Priority::Grow, 'biceps' => Priority::Maintain],
        experience: ExperienceLevel::Intermediate, setCounts: $sets, muscleAnswers: $muscles, jointPain: $pain,
        held: $held, now: new DateTimeImmutable('2026-10-12 18:30:00'),
    );
}

/** Pump low and volume not enough: a score of 2, so an Emphasize muscle wants 2 sets and a Grow one 1. */
function applyWantsMore(): FeedbackAnswers
{
    return new FeedbackAnswers(pump: Pump::Low, volume: VolumeFeedback::NotEnough);
}

function applyTooMuch(): FeedbackAnswers
{
    return new FeedbackAnswers(pump: Pump::Moderate, volume: VolumeFeedback::TooMuch);
}

it('PROG-019 the base is the sets the session ran, including added and deleted ones', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(bench: 4, row: 2)));

    expect($result->slotSets)->toBe(['bench' => 4, 'incline' => 3, 'row' => 2, 'curl' => 2])
        ->and($result->previous)->toBe(['bench' => 3, 'incline' => 3, 'row' => 3, 'curl' => 2])
        ->and($result->changes)->toBe(['chest' => 1, 'back' => -1])
        ->and($result->lines)->toBe(['Chest +1 set next week', 'Back −1 set next week'])
        ->and($result->changed())->toBeTrue();
});

it('PROG-019 a slot whose exercise has no sets keeps its stored count', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(chest: [3, 4]), applyRan(bench: 5, incline: null)));

    expect($result->slotSets['incline'])->toBe(4)
        ->and($result->slotSets['bench'])->toBe(5);
});

it('PROG-019 sets of exercises not in the day\'s slots are ignored', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(others: [99 => 6, 30 => 4])));

    expect($result->slotSets)->toBe($result->previous)
        ->and($result->changed())->toBeFalse()
        ->and($result->lines)->toBe([]);
});

it('PROG-019 one exercise in two slots gives its base to the first only', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(extra: applySlot('bench-again', 12, 'chest', 2)), applyRan(bench: 4)));

    expect($result->slotSets['bench'])->toBe(4)
        ->and($result->slotSets['bench-again'])->toBe(2);
});

it('PROG-019 only the session\'s own day changes', function () {
    $days = applyDays();
    $updated = ProgressionApply::apply(applyInput($days, applyRan(bench: 4)))->applying($days);

    expect(array_column($updated[0]['slots'], 'sets'))->toBe([4, 3, 3, 2])
        ->and($updated[1])->toBe($days[1])
        ->and(array_column($updated[0]['slots'], 'id'))->toBe(array_column($days[0]['slots'], 'id'));
});

it('PROG-019 applying twice with the same answers changes nothing', function () {
    $days = applyDays();
    $muscles = ['chest' => applyWantsMore(), 'back' => applyWantsMore()];
    $one = ProgressionApply::apply(applyInput($days, applyRan(bench: 4), $muscles));
    $two = ProgressionApply::apply(applyInput($one->applying($days), applyRan(bench: 4), $muscles));

    expect($one->changed())->toBeTrue()
        ->and($two->slotSets)->toBe($one->slotSets)
        ->and($two->previous)->toBe($one->slotSets)
        ->and($two->changed())->toBeFalse()
        ->and($two->lines)->toBe([]);
});

it('PROG-010 a muscle that wants more gains sets on its first slot and the toast says so', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(), ['chest' => applyWantsMore()]));

    expect($result->slotSets)->toBe(['bench' => 5, 'incline' => 3, 'row' => 3, 'curl' => 2])
        ->and($result->changes)->toBe(['chest' => 2])
        ->and($result->lines)->toBe(['Chest +2 sets next week']);
});

it('PROG-018 joint pain moves the added sets to another exercise', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(), ['chest' => applyWantsMore()], [12 => JointPain::Moderate, 13 => JointPain::None]));

    expect($result->slotSets['bench'])->toBe(3)
        ->and($result->slotSets['incline'])->toBe(5);
});

it('PROG-013 too much takes a set from the exercise with the worst pain', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(), ['chest' => applyTooMuch()], [12 => JointPain::ALot, 13 => JointPain::Low]));

    expect($result->slotSets['bench'])->toBe(2)
        ->and($result->slotSets['incline'])->toBe(3)
        ->and($result->lines)->toBe(['Chest −1 set next week']);
});

it('PROG-010 a muscle with no feedback keeps its sets and adds no line', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(), ['back' => applyWantsMore()]));

    expect($result->changes)->toBe(['back' => 1])
        ->and($result->slotSets['bench'])->toBe(3)
        ->and($result->lines)->toBe(['Back +1 set next week']);
});

it('PROG-015 the recovery limit cuts the add and the toast says so', function () {
    // Chest is at 23 sets a week and the ceiling is 24: one of the two wanted sets fits.
    $result = ProgressionApply::apply(applyInput(applyDays(fly: 17), applyRan(), ['chest' => applyWantsMore()]));

    expect($result->changes)->toBe(['chest' => 1])
        ->and($result->lines)->toBe(['Chest +1 set next week', 'At your recovery limit for Chest']);
});

it('PROG-017 no room left suggests adding an exercise', function () {
    // Both chest exercises are at 5 sets, so the add has nowhere to go. Back lost a set, so there is a toast.
    $result = ProgressionApply::apply(applyInput(applyDays(chest: [5, 5]), applyRan(bench: 5, incline: 5, row: 2), ['chest' => applyWantsMore()]));

    expect($result->changes)->toBe(['back' => -1])
        ->and($result->lines)->toBe(['Back −1 set next week', 'Add an exercise for Chest to keep growing its sets']);
});

it('PROG-051 a held muscle gains no sets', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(), ['chest' => applyWantsMore(), 'back' => applyWantsMore()], held: [MuscleGroup::Chest]));

    expect([$result->slotSets['bench'], $result->slotSets['incline']])->toBe([3, 3])
        ->and($result->changes)->toBe(['back' => 1])
        ->and($result->lines)->toBe(['Back +1 set next week']);
});

it('PROG-051 a hold drops the messages of the add it stopped', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(fly: 17), applyRan(row: 2), ['chest' => applyWantsMore()], held: [MuscleGroup::Chest]));

    expect($result->lines)->toBe(['Back −1 set next week']);
});

it('PROG-050 a held muscle still loses sets when its answers say too much', function () {
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(), ['chest' => applyTooMuch()], held: [MuscleGroup::Chest]));

    expect($result->slotSets['incline'])->toBe(2)
        ->and($result->lines)->toBe(['Chest −1 set next week']);
});

it('PROG-050 a hold keeps the sets the user added by hand', function () {
    // The hold goes back to the base, which is the session as it ran: the extra set stays (PROG-019).
    $result = ProgressionApply::apply(applyInput(applyDays(), applyRan(bench: 4), ['chest' => applyWantsMore()], held: [MuscleGroup::Chest]));

    expect($result->slotSets['bench'])->toBe(4)
        ->and($result->lines)->toBe(['Chest +1 set next week']);
});

it('PROG-014 Dr. Mike\'s example: Grow, 3 sets, never sore with a low pump, gives 4 next week', function () {
    $days = [['id' => 'day-1', 'label' => 'Back', 'weekday' => 1, 'slots' => [applySlot('row', 25, 'back', 3)]]];
    $answers = ['back' => new FeedbackAnswers(soreness: Soreness::NeverGotSore, pump: Pump::Low, volume: VolumeFeedback::JustRight)];

    $result = ProgressionApply::apply(applyInput($days, [25 => 3], $answers));

    expect($result->slotSets)->toBe(['row' => 4]);
});
