<?php

use App\Capabilities\Reads\Exercises\ExercisePayload;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

// One exercise's Detail and History tabs (rp-clone ExerciseDetailModelTests), from in-memory rows. Saving the note and
// the step, and the field errors, are the actions' and the seam's tests.

function detailExercise(array $changes = []): Exercise
{
    return (new Exercise)->forceFill([
        'id' => 12, 'user_id' => null, 'name' => 'Bench Press', 'muscle_group' => 'sideDelts', 'equipment' => 'smithMachine',
        'load_type' => 'barbell', 'rep_range_min' => 10, 'rep_range_max' => 20, 'video_url' => null,
        ...$changes,
    ]);
}

/** @param  array<string, mixed>  $with */
function detailOf(array $with = []): array
{
    $with = [
        'exercise' => detailExercise(), 'userId' => 7, 'unit' => WeightUnit::Kg, 'microPlates' => false,
        'inCurrentMesocycle' => false, 'note' => null, 'stepKg' => null, 'sets' => [], ...$with,
    ];

    return ExercisePayload::build(
        $with['exercise'], $with['userId'], $with['unit'], $with['microPlates'], $with['inCurrentMesocycle'],
        $with['note'], $with['stepKg'], new Collection($with['sets']),
    )->toArray();
}

function detailSession(string $id, ?string $actual, ?string $planned = null): WorkoutSession
{
    return (new WorkoutSession)->forceFill(['id' => $id, 'user_id' => 7, 'actual_start_at' => $actual, 'planned_start_at' => $planned]);
}

function detailSet(string $id, WorkoutSession $session, ?float $kg, ?int $reps): WorkoutSet
{
    return (new WorkoutSet)->forceFill([
        'id' => $id, 'user_id' => 7, 'workout_session_id' => $session->id, 'exercise_id' => 12,
        'actual_weight_kg' => $kg, 'actual_reps' => $reps, 'is_logged' => true,
    ])->setRelation('session', $session);
}

it('EX-002 EX-005 shows the muscle, equipment and load type as values and titles', function () {
    $payload = detailOf(['exercise' => detailExercise(['load_type' => 'bodyweightPlusLoad'])]);

    expect($payload['id'])->toBe('12')
        ->and($payload['name'])->toBe('Bench Press')
        ->and($payload['muscle_group'])->toBe('sideDelts')
        ->and($payload['muscle_group_text'])->toBe('Side delts')
        ->and($payload['equipment'])->toBe('smithMachine')
        ->and($payload['equipment_text'])->toBe('Smith machine')
        ->and($payload['load_type'])->toBe('bodyweightPlusLoad')
        ->and($payload['load_type_text'])->toBe('Bodyweight plus load');
});

it('EX-004 offers edit on an exercise only when the user made it', function () {
    expect(detailOf()['is_custom'])->toBeFalse()
        ->and(detailOf(['exercise' => detailExercise(['user_id' => 7])])['is_custom'])->toBeTrue()
        ->and(detailOf(['exercise' => detailExercise(['user_id' => 8])])['is_custom'])->toBeFalse();
});

it('EX-001 gives the video link only when it is a usable http or https link', function (?string $url, ?string $expected) {
    expect(detailOf(['exercise' => detailExercise(['video_url' => $url])])['video_url'])->toBe($expected);
})->with([
    'a youtube link' => ['https://www.youtube.com/watch?v=abc', 'https://www.youtube.com/watch?v=abc'],
    'a plain http link' => ['http://example.com/a.mp4', 'http://example.com/a.mp4'],
    'null' => [null, null],
    'empty' => ['', null],
    'spaces' => ['   ', null],
    'a script' => ['javascript:alert(1)', null],
    'not a url' => ['not a url', null],
]);

it('EX-007 says the exercise\'s own rep range only when it is in the current mesocycle', function () {
    $exercise = detailExercise(['rep_range_min' => 5, 'rep_range_max' => 10]);

    expect(detailOf(['exercise' => $exercise, 'inCurrentMesocycle' => true])['rep_range_text'])->toBe('5–10 reps')
        ->and(detailOf(['exercise' => $exercise, 'inCurrentMesocycle' => false])['rep_range_text'])->toBeNull();
});

it('EX-010 shows the pinned note, or null without one', function () {
    expect(detailOf(['note' => 'Pause on the chest'])['pinned_note'])->toBe('Pause on the chest')
        ->and(detailOf()['pinned_note'])->toBeNull();
});

it('EX-012 TBL-040 shows the default step in the user\'s unit, and the micro plate step where the table has one', function (string $loadType, WeightUnit $unit, bool $micro, string $expected) {
    $payload = detailOf(['exercise' => detailExercise(['load_type' => $loadType]), 'unit' => $unit, 'microPlates' => $micro]);

    expect($payload['load_step']['default_text'])->toBe($expected)
        ->and($payload['load_step']['unit'])->toBe($unit->value);
})->with([
    'a barbell' => ['barbell', WeightUnit::Kg, false, '2.5 kg'],
    'a smith machine' => ['smithMachine', WeightUnit::Kg, false, '2.5 kg'],
    'a dumbbell' => ['dumbbell', WeightUnit::Kg, false, '2 kg'],
    'a cable' => ['cable', WeightUnit::Kg, false, '2.5 kg'],
    'a machine' => ['machine', WeightUnit::Kg, false, '2.5 kg'],
    'bodyweight plus load' => ['bodyweightPlusLoad', WeightUnit::Kg, false, '1.25 kg'],
    'a barbell in pounds' => ['barbell', WeightUnit::Lb, false, '5 lb'],
    'bodyweight plus load in pounds' => ['bodyweightPlusLoad', WeightUnit::Lb, false, '2.5 lb'],
    'a barbell with micro plates' => ['barbell', WeightUnit::Kg, true, '1.25 kg'],
    'a barbell with micro plates in pounds' => ['barbell', WeightUnit::Lb, true, '2.5 lb'],
    'a dumbbell with micro plates, which the table has no step for' => ['dumbbell', WeightUnit::Kg, true, '2 kg'],
]);

it('EX-012 gives a bodyweight exercise no load step: it is reps only', function () {
    expect(detailOf(['exercise' => detailExercise(['load_type' => 'bodyweight']), 'stepKg' => 2.5])['load_step'])->toBeNull();
});

it('EX-012 SET-001 shows the saved step in the user\'s unit, rounded to 2 decimals', function () {
    $kg = detailOf(['stepKg' => 4.5])['load_step'];
    $lb = detailOf(['stepKg' => 4.5, 'unit' => WeightUnit::Lb])['load_step'];

    expect($kg['step'])->toBe(['value' => 4.5, 'unit' => 'kg', 'text' => '4.5 kg'])
        ->and($lb['step'])->toBe(['value' => 9.92, 'unit' => 'lb', 'text' => '9.92 lb'])
        ->and(detailOf()['load_step']['step'])->toBeNull();
});

it('SET-001 reads a step typed in pounds back as typed', function () {
    // 10 lb is stored as 4.5359237 kg, unrounded.
    $step = detailOf(['stepKg' => 10 * 0.45359237, 'unit' => WeightUnit::Lb])['load_step']['step'];

    expect($step)->toBe(['value' => 10.0, 'unit' => 'lb', 'text' => '10 lb']);
});

it('EX-002 lists the history newest day first, with the sets in position order', function () {
    $older = detailSession('a1', '2026-10-01 07:30:00');
    $newer = detailSession('a2', '2026-10-08 07:30:00');
    $history = detailOf(['sets' => [
        detailSet('s1', $older, 50.0, 10), detailSet('s2', $older, 52.5, 10),
        detailSet('s9', $newer, 60.0, 8), detailSet('s10', $newer, 60.0, 7),
    ]])['history'];

    expect(array_column($history, 'workout_id'))->toBe(['a2', 'a1'])
        ->and(array_column($history, 'date_text'))->toBe(['Thu, 8 Oct 2026', 'Thu, 1 Oct 2026'])
        ->and(array_column($history[0]['sets'], 'id'))->toBe(['s9', 's10'])
        ->and(array_column($history[0]['sets'], 'text'))->toBe(['60.0 kg × 8', '60.0 kg × 7'])
        ->and(array_column($history[1]['sets'], 'text'))->toBe(['50.0 kg × 10', '52.5 kg × 10']);
});

it('EX-002 marks the best e1RM set of a day with a pill, only the first of ties, and says the best', function () {
    $day = detailSession('a2', '2026-10-08 07:30:00');
    $history = detailOf(['sets' => [
        detailSet('s9', $day, 60.0, 8), detailSet('s10', $day, 60.0, 7), detailSet('s11', $day, 60.0, 8),
    ]])['history'];

    expect(array_column($history[0]['sets'], 'badge'))->toBe([['text' => 'Best e1RM', 'tone' => 'good'], null, null])
        ->and($history[0]['best_text'])->toBe('Best e1RM 76.0 kg');
});

it('EX-002 shows the history in pounds when that is the unit', function () {
    $day = detailSession('a1', '2026-10-01 07:30:00');
    $history = detailOf(['unit' => WeightUnit::Lb, 'sets' => [detailSet('s1', $day, 50.0, 10)]])['history'];

    expect($history[0]['sets'][0]['text'])->toBe('110.2 lb × 10')
        ->and($history[0]['best_text'])->toBe('Best e1RM 147.0 lb');
});

it('EX-002 shows a missing weight or reps as a dash, and a day with no load has no best and no pill', function () {
    $day = detailSession('a1', '2026-10-01 07:30:00');
    $history = detailOf(['sets' => [detailSet('s1', $day, null, 10), detailSet('s2', $day, null, null)]])['history'];

    expect(array_column($history[0]['sets'], 'text'))->toBe(['– × 10', '– × –'])
        ->and(array_column($history[0]['sets'], 'badge'))->toBe([null, null])
        ->and($history[0]['best_text'])->toBeNull();
});

it('EX-002 has an empty history for an exercise never trained', function () {
    expect(detailOf()['history'])->toBe([]);
});
