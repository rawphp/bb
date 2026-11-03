<?php

use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Support\Collection;

// SCH-050 to SCH-057, SET-012: a stored session as the week's placement reads it, its start in the user's zone.

function recordTrain(string $zone): TrainSnapshot
{
    return new TrainSnapshot(
        today: TrainSnapshot::day('2026-09-21'),
        plan: null,
        profile: null,
        mesocycles: new Collection,
        phases: new Collection,
        sessions: new Collection,
        sets: new Collection,
        feedback: new Collection,
        planWeeks: new Collection,
        dayTargets: new Collection,
        exercises: new Collection,
        zone: new DateTimeZone($zone),
    );
}

it('SET-012 a started session is recorded with its start in the user\'s zone and its planned start as stored', function () {
    $session = (new WorkoutSession)->forceFill([
        'id' => 'session-1', 'mesocycle_id' => 'meso-a', 'week' => 2, 'day' => 3, 'is_skipped' => false,
        'planned_start_at' => '2026-09-22 07:00:00', 'actual_start_at' => '2026-09-22 21:30:00',
    ]);

    $record = recordTrain('Australia/Brisbane')->record($session);

    expect($record->id)->toBe('session-1')
        ->and($record->mesocycleId)->toBe('meso-a')
        ->and($record->week)->toBe(2)
        ->and($record->day)->toBe(3)
        ->and($record->isSkipped)->toBeFalse()
        ->and($record->plannedStartAt?->format('Y-m-d H:i'))->toBe('2026-09-22 07:00')
        ->and($record->actualStartAt?->format('Y-m-d H:i'))->toBe('2026-09-23 07:30');
});

it('SCH-057 a skipped session with no times is recorded with null starts', function () {
    $session = (new WorkoutSession)->forceFill([
        'id' => 'session-2', 'mesocycle_id' => 'meso-a', 'week' => 1, 'day' => null, 'is_skipped' => true,
        'planned_start_at' => null, 'actual_start_at' => null,
    ]);

    $record = recordTrain('UTC')->record($session);

    expect($record->isSkipped)->toBeTrue()
        ->and($record->day)->toBeNull()
        ->and($record->plannedStartAt)->toBeNull()
        ->and($record->actualStartAt)->toBeNull();
});
