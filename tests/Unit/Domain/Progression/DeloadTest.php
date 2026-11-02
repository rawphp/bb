<?php

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Session;
use App\Domain\Progression\Deload;
use App\Domain\Progression\DeloadHalf;

it('PROG-041 the first half halves sets and reps at the same weight', function () {
    expect(Deload::sets(4))->toBe(2)
        ->and(Deload::reps(10))->toBe(5)
        ->and(Deload::weight(80, DeloadHalf::First))->toBe(80.0);
});

it('PROG-041 the second half also halves the weight', function () {
    expect(Deload::sets(4))->toBe(2)
        ->and(Deload::reps(10))->toBe(5)
        ->and(Deload::weight(80, DeloadHalf::Second))->toBe(40.0);
});

it('PROG-041 rounds down with a minimum of one set', function () {
    expect(Deload::sets(5))->toBe(2)
        ->and(Deload::sets(3))->toBe(1)
        ->and(Deload::sets(2))->toBe(1)
        ->and(Deload::sets(1))->toBe(1)
        ->and(Deload::reps(11))->toBe(5)
        ->and(Deload::reps(8))->toBe(4);
});

it('PROG-041 splits the week into halves by session count', function () {
    $halves = fn (int $count) => array_map(
        fn (int $index) => Deload::half($index, $count),
        range(0, $count - 1),
    );
    [$first, $second] = [DeloadHalf::First, DeloadHalf::Second];

    expect($halves(1))->toBe([$first])
        ->and($halves(2))->toBe([$first, $second])
        ->and($halves(3))->toBe([$first, $first, $second])
        ->and($halves(4))->toBe([$first, $first, $second, $second])
        ->and($halves(5))->toBe([$first, $first, $first, $second, $second]);
});

it('PROG-042 removes traps and forearms and keeps everything else', function () {
    expect(Deload::REMOVED_MUSCLES)->toBe([MuscleGroup::Traps, MuscleGroup::Forearms]);
    $slots = [
        new ExerciseSlot('s0', 'Bench', MuscleGroup::Chest, 4),
        new ExerciseSlot('s1', 'Shrug', MuscleGroup::Traps, 3),
        new ExerciseSlot('s2', 'Curl', MuscleGroup::Biceps, 3),
        new ExerciseSlot('s3', 'Wrist curl', MuscleGroup::Forearms, 2),
        new ExerciseSlot('s4', 'Row', MuscleGroup::Back, 1),
    ];
    $session = new Session('upper', 'Upper', $slots);
    $deloaded = Deload::session($session);

    expect($deloaded->id)->toBe('upper')
        ->and($deloaded->name)->toBe('Upper')
        ->and(array_map(fn (ExerciseSlot $s) => $s->exerciseName, $deloaded->slots))->toBe(['Bench', 'Curl', 'Row'])
        ->and(array_map(fn (ExerciseSlot $s) => $s->sets, $deloaded->slots))->toBe([2, 1, 1])
        ->and(array_map(fn (ExerciseSlot $s) => $s->id, $deloaded->slots))->toBe(['s0', 's2', 's4']);
});
