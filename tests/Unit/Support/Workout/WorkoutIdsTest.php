<?php

use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Str;

// OFF-021: a workout, its exercises and its planned sets have ids before the session row exists, so the outbox can
// queue a start and then a logged set. The ids come from what they name, so they are the same on every read.

const IDS_MESO = '0195a000-0000-7000-8000-00000000abcd';

it('OFF-021 a workout id is ULID-shaped, lowercase and the same on every read', function () {
    $id = WorkoutIds::workout(IDS_MESO, 3, 1);

    expect(Str::isUlid($id))->toBeTrue()
        ->and($id)->toBe(strtolower($id))
        ->and(WorkoutIds::workout(IDS_MESO, 3, 1))->toBe($id)
        ->and(WorkoutIds::workout(strtoupper(IDS_MESO), 3, 1))->toBe($id);
});

it('OFF-021 each week, day, exercise and set has its own id', function () {
    $workout = WorkoutIds::workout(IDS_MESO, 3, 1);
    $ids = [
        $workout,
        WorkoutIds::workout(IDS_MESO, 3, 2),
        WorkoutIds::workout(IDS_MESO, 4, 1),
        WorkoutIds::block($workout, 'slot-a'),
        WorkoutIds::block($workout, 'slot-b'),
        WorkoutIds::block(WorkoutIds::workout(IDS_MESO, 4, 1), 'slot-a'),
        WorkoutIds::set($workout, 'slot-a', 0),
        WorkoutIds::set($workout, 'slot-a', 1),
        WorkoutIds::set($workout, 'slot-b', 0),
    ];

    expect(array_unique($ids))->toHaveCount(count($ids));
    foreach ($ids as $id) {
        expect(Str::isUlid($id))->toBeTrue($id);
    }
});
