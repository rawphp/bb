<?php

use App\Capabilities\Reads\Progress\ExerciseHistoryPayload;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

// One exercise's history (rp-clone ExerciseHistoryModelTests), from in-memory rows.

function historySession(string $id, ?string $actual, ?string $planned = null): WorkoutSession
{
    return (new WorkoutSession)->forceFill(['id' => $id, 'user_id' => 1, 'mesocycle_id' => null, 'actual_start_at' => $actual, 'planned_start_at' => $planned]);
}

function historySet(string $id, WorkoutSession $session, ?float $kg, ?int $reps): WorkoutSet
{
    return (new WorkoutSet)->forceFill([
        'id' => $id, 'user_id' => 1, 'workout_session_id' => $session->id, 'exercise_id' => 12,
        'actual_weight_kg' => $kg, 'actual_reps' => $reps, 'is_logged' => true,
    ])->setRelation('session', $session);
}

/** @return list<WorkoutSet> rp-clone's two sessions, listed oldest first */
function benchSets(): array
{
    $a1 = historySession('a1', '2026-10-01 07:30:00');
    $a2 = historySession('a2', '2026-10-08 07:30:00');

    return [
        historySet('s1', $a1, 50.0, 10), historySet('s2', $a1, 52.5, 10),
        historySet('s9', $a2, 60.0, 8), historySet('s10', $a2, 60.0, 7), historySet('s11', $a2, 60.0, 8),
    ];
}

function history(array $sets, WeightUnit $unit = WeightUnit::Kg): array
{
    $bench = (new Exercise)->forceFill(['id' => 12, 'name' => 'Bench Press', 'muscle_group' => 'chest']);

    return ExerciseHistoryPayload::build($bench, new Collection($sets), $unit)->toArray();
}

it('ANA-009 shows the exercise, newest session first, with its sets in order', function () {
    $payload = history(benchSets());

    expect($payload['title'])->toBe('Bench Press')
        ->and($payload['subtitle'])->toBe('Chest')
        ->and($payload['best'])->toBe(['text' => 'Best e1RM', 'tone' => 'good'])
        ->and(array_column($payload['sessions'], 'id'))->toBe(['a2', 'a1'])
        ->and(array_column($payload['sessions'][0]['sets'], 'id'))->toBe(['s9', 's10', 's11'])
        ->and(array_column($payload['sessions'], 'date_label'))->toBe(['Thu, 8 Oct 2026', 'Thu, 1 Oct 2026']);
});

it('ANA-009 every set shows weight times reps', function () {
    $sessions = history(benchSets())['sessions'];

    expect(array_column($sessions[0]['sets'], 'label'))->toBe(['60.0 kg × 8', '60.0 kg × 7', '60.0 kg × 8'])
        ->and(array_column($sessions[1]['sets'], 'label'))->toBe(['50.0 kg × 10', '52.5 kg × 10']);
});

it('ANA-009 marks the best e1RM set, only the first of ties', function () {
    $sessions = history(benchSets())['sessions'];

    // Session a2: s9 and s11 both reach 76.0; only the first is marked.
    expect(array_column($sessions[0]['sets'], 'is_best'))->toBe([true, false, false])
        ->and(array_column($sessions[1]['sets'], 'is_best'))->toBe([false, true])
        ->and($sessions[0]['best_label'])->toBe('Best e1RM 76.0 kg')
        ->and($sessions[1]['best_label'])->toBe('Best e1RM 70.0 kg');
});

it('ANA-009 shows pounds in the pounds unit', function () {
    $sessions = history(benchSets(), WeightUnit::Lb)['sessions'];

    expect(array_column($sessions[1]['sets'], 'label'))->toBe(['110.2 lb × 10', '115.7 lb × 10'])
        ->and($sessions[1]['best_label'])->toBe('Best e1RM 154.3 lb');
});

it('ANA-009 a set without weight or reps shows dashes, and a session without a date is "Session"', function () {
    $undated = historySession('a1', null);
    $payload = history([historySet('s1', $undated, null, null), historySet('s2', $undated, 60.0, null), historySet('s3', $undated, null, 8)]);

    $session = $payload['sessions'][0];

    expect($session['date_label'])->toBe('Session')
        ->and(array_column($session['sets'], 'label'))->toBe(['– × –', '60.0 kg × –', '– × 8'])
        ->and($session['best_label'])->toBe('Best e1RM 60.0 kg')
        ->and(array_column($session['sets'], 'is_best'))->toBe([false, true, false]);
});

it('ANA-009 a session whose only set has no weight or reps has a best e1RM of 0.0 kg on that set', function () {
    $session = history([historySet('s1', historySession('a1', null), null, null)])['sessions'][0];

    expect($session['best_label'])->toBe('Best e1RM 0.0 kg')
        ->and($session['sets'][0]['is_best'])->toBeTrue();
});

it('ANA-009 dates a session by its actual start, else its planned start, and puts the undated last', function () {
    $payload = history([
        historySet('u', historySession('undated', null), 50.0, 5),
        historySet('p', historySession('planned', null, '2026-10-19 09:00:00'), 50.0, 5),
        historySet('b', historySession('both', '2026-10-20 08:00:00', '2026-10-26 09:00:00'), 50.0, 5),
        historySet('o', historySession('old', '2026-10-12 09:05:00'), 50.0, 5),
    ]);

    expect(array_column($payload['sessions'], 'id'))->toBe(['both', 'planned', 'old', 'undated'])
        ->and(array_column($payload['sessions'], 'date_label'))->toBe(['Tue, 20 Oct 2026', 'Mon, 19 Oct 2026', 'Mon, 12 Oct 2026', 'Session']);
});

it('EX-002 an exercise never trained has an empty history', function () {
    expect(history([])['sessions'])->toBe([]);
});

it('EX-002 orders sessions of the same date by id, the greater id first, whatever order their sets come in', function () {
    $at = '2026-10-12 09:00:00';
    $payload = history([
        historySet('s1', historySession('a', $at), 50.0, 5),
        historySet('s2', historySession('c', null), 50.0, 5),
        historySet('s3', historySession('b', $at), 50.0, 5),
        historySet('s4', historySession('d', null), 50.0, 5),
    ]);

    expect(array_column($payload['sessions'], 'id'))->toBe(['b', 'a', 'd', 'c']);
});

it('EX-002 passes on the id to read older sessions with, and has none by default', function () {
    $bench = (new Exercise)->forceFill(['id' => 12, 'name' => 'Bench Press', 'muscle_group' => 'chest']);

    expect(ExerciseHistoryPayload::build($bench, new Collection(benchSets()), WeightUnit::Kg, 'a1')->toArray()['next_before'])->toBe('a1')
        ->and(history(benchSets())['next_before'])->toBeNull();
});
