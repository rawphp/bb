<?php

use App\Domain\Mesocycle\DayEdits;

// MESO-045, EX-008: what a session edit with "Apply to all remaining weeks" (or a rest-of-mesocycle swap) does to the
// day's slots (rp-clone WorkoutDayEditTests). Upper A: Bench Press (12), Barbell Row (25), Dumbbell Curl (40).

/** @return list<array{id: string, exercise_id: ?int, muscle_group: string, sets: int, carried: ?array{weight_kg: float, reps: int}}> */
function upperA(): array
{
    return [
        ['id' => 'slot-12', 'exercise_id' => 12, 'muscle_group' => 'chest', 'sets' => 3, 'carried' => ['weight_kg' => 80.0, 'reps' => 10]],
        ['id' => 'slot-25', 'exercise_id' => 25, 'muscle_group' => 'back', 'sets' => 3, 'carried' => null],
        ['id' => 'slot-40', 'exercise_id' => 40, 'muscle_group' => 'biceps', 'sets' => 2, 'carried' => null],
    ];
}

it('MESO-045 adding an exercise to the remaining weeks appends a three-set slot for its muscle', function () {
    $slots = DayEdits::appending(upperA(), 'slot-60', 60, 'calves');

    expect(array_column($slots, 'exercise_id'))->toBe([12, 25, 40, 60])
        ->and($slots[3])->toBe(['id' => 'slot-60', 'exercise_id' => 60, 'muscle_group' => 'calves', 'sets' => 3, 'carried' => null])
        ->and(array_slice($slots, 0, 3))->toBe(upperA());
});

it('MESO-045 a reorder writes the session\'s order to the day, slots keeping their ids', function () {
    $slots = DayEdits::following(upperA(), [12, 40, 25]);

    expect(array_column($slots, 'exercise_id'))->toBe([12, 40, 25])
        ->and(array_column($slots, 'id'))->toBe(['slot-12', 'slot-40', 'slot-25'])
        ->and($slots[0]['carried'])->toBe(['weight_kg' => 80.0, 'reps' => 10]);
});

it('MESO-045 an exercise only this session has does not join the day, and the day\'s own slots follow the order', function () {
    // Calf Raise (60) went to the top and Barbell Row above Bench Press.
    expect(array_column(DayEdits::following(upperA(), [60, 25, 12, 40]), 'exercise_id'))->toBe([25, 12, 40]);
});

it('MESO-045 a slot whose exercise is not in the session keeps its place', function () {
    $slots = [...upperA(), ['id' => 'slot-empty', 'exercise_id' => null, 'muscle_group' => 'calves', 'sets' => 2, 'carried' => null]];
    $slots = [$slots[3], ...array_slice($slots, 0, 3)];

    // The session dropped Bench Press (12) and runs Curl before Row.
    expect(array_column(DayEdits::following($slots, [40, 25]), 'id'))->toBe(['slot-empty', 'slot-12', 'slot-40', 'slot-25']);
});

it('MESO-045 an exercise twice in the session ranks by its first place', function () {
    expect(array_column(DayEdits::following(upperA(), [40, 12, 40, 25]), 'exercise_id'))->toBe([40, 12, 25]);
});

it('EX-008 a rest-of-mesocycle swap changes the day\'s one slot of the old exercise and drops its carried load', function () {
    $slots = DayEdits::swapping(upperA(), 12, 14);

    expect(array_column($slots, 'exercise_id'))->toBe([14, 25, 40])
        ->and($slots[0])->toBe(['id' => 'slot-12', 'exercise_id' => 14, 'muscle_group' => 'chest', 'sets' => 3, 'carried' => null]);
});

it('EX-008 a swap of the slot a session exercise was planned from changes that slot, and changes nothing once it has', function () {
    $slots = [...upperA(), ['id' => 'slot-12b', 'exercise_id' => 12, 'muscle_group' => 'chest', 'sets' => 2, 'carried' => null]];

    $swapped = DayEdits::swapping($slots, 12, 14, 'slot-12b');

    expect(array_column($swapped, 'exercise_id'))->toBe([12, 25, 40, 14])
        ->and(DayEdits::swapping($swapped, 12, 14, 'slot-12b'))->toBe($swapped);
});

it('EX-008 with no slot named, a day that runs the exercise twice is left alone: which slot is meant is unknown', function () {
    $slots = [...upperA(), ['id' => 'slot-12b', 'exercise_id' => 12, 'muscle_group' => 'chest', 'sets' => 2, 'carried' => null]];

    expect(DayEdits::swapping($slots, 12, 14))->toBe($slots);
});

it('EX-008 a swap of an exercise the day does not hold leaves the day alone', function () {
    expect(DayEdits::swapping(upperA(), 99, 14))->toBe(upperA());
});

it('WO-031 a day label is trimmed, and a blank one is no name', function () {
    expect(DayEdits::label('  Push  '))->toBe('Push')
        ->and(DayEdits::label(" \t "))->toBeNull();
});
