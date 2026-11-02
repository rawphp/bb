<?php

use App\Capabilities\Reads\Exercises\ExercisesPayload;
use App\Models\Exercise;
use Illuminate\Support\Collection;

// The exercise library (rp-clone ExerciseLibraryModelTests), from in-memory rows. Search and the muscle and equipment
// filters run on the device (EX-003), so they have no test here.

function libraryExercise(int $id, string $name, string $muscle = 'chest', string $equipment = 'barbell', ?string $video = null): Exercise
{
    return (new Exercise)->forceFill([
        'id' => $id, 'name' => $name, 'muscle_group' => $muscle, 'equipment' => $equipment, 'load_type' => 'barbell', 'video_url' => $video,
    ]);
}

/** @param  array<int, ?DateTimeImmutable>  $lastUsed */
function library(array $exercises, array $lastUsed = []): array
{
    return ExercisesPayload::build(new Collection($exercises), $lastUsed)->toArray();
}

it('EX-003 sorts the library by name, ignoring case, and by id when two names are the same', function () {
    $payload = library([
        libraryExercise(3, 'Squat'), libraryExercise(1, 'bench Press'), libraryExercise(2, 'Dumbbell Bench Press'),
        libraryExercise(4, 'Cable Fly'), libraryExercise(6, 'curl'), libraryExercise(5, 'Curl'),
    ]);

    expect(array_column($payload['exercises'], 'name'))->toBe(['bench Press', 'Cable Fly', 'curl', 'Curl', 'Dumbbell Bench Press', 'Squat'])
        ->and(array_column($payload['exercises'], 'id'))->toBe(['1', '4', '5', '6', '2', '3']);
});

it('EX-003 shows a row as the muscle and equipment titles, with the keys the filters match', function () {
    $row = library([libraryExercise(3, 'Squat', 'sideDelts', 'smithMachine')])['exercises'][0];

    expect($row['detail'])->toBe('Side delts · Smith machine')
        ->and($row['muscle_group'])->toBe('sideDelts')
        ->and($row['equipment'])->toBe('smithMachine');
});

it('EX-013 says when an exercise was last used, and "Never used" when it was not', function () {
    $payload = library(
        [libraryExercise(3, 'Squat'), libraryExercise(1, 'Bench')],
        [3 => new DateTimeImmutable('2026-10-03 01:00:00')],
    );

    expect(array_column($payload['exercises'], 'last_used_text', 'name'))->toBe(['Bench' => 'Never used', 'Squat' => 'Last used 3 Oct 2026']);
});

it('EX-001 marks a row with a video only for a usable http or https link', function (?string $url, bool $usable) {
    expect(library([libraryExercise(1, 'Bench', video: $url)])['exercises'][0]['has_video'])->toBe($usable);
})->with([
    'a youtube link' => ['https://www.youtube.com/watch?v=abc', true],
    'a plain http link' => ['http://example.com/bench.mp4', true],
    'a link with spaces around it' => ['  https://example.com/bench  ', true],
    'null' => [null, false],
    'empty' => ['', false],
    'spaces' => ['   ', false],
    'a script' => ['javascript:alert(1)', false],
    'not a url' => ['not a url', false],
    'a scheme with no host' => ['https://', false],
    'a file link' => ['file:///etc/passwd', false],
]);

it('EX-003 lists every muscle group, piece of equipment and load type in declared order with its label', function () {
    $payload = library([]);

    expect($payload['exercises'])->toBe([])
        ->and($payload['muscle_groups'][0])->toBe(['value' => 'chest', 'label' => 'Chest'])
        ->and($payload['muscle_groups'])->toHaveCount(14)
        ->and($payload['equipment'][1])->toBe(['value' => 'ezBar', 'label' => 'EZ bar'])
        ->and($payload['equipment'])->toHaveCount(9)
        ->and($payload['load_types'][5])->toBe(['value' => 'bodyweightPlusLoad', 'label' => 'Bodyweight plus load'])
        ->and($payload['load_types'])->toHaveCount(7);
});
