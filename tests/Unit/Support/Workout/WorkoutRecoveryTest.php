<?php

use App\Capabilities\Reads\Workout\WorkoutPayload;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Domain\Progression\RecoveryLoad;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\Mesocycle;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutSetRow;
use App\Support\Workout\WorkoutSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// The recovery flags on the targets a session starts with (PROG-018, PROG-051, PROG-053) and the swap suggestion
// (EX-009), gathered from the stored sets and feedback, from rp-clone's WorkoutRecoveryTests. The rules themselves are
// RecoveryLoadTest and JointPainSwapTest. Meso 2's Upper A on Monday: Bench Press (barbell, 5 to 30 reps) for chest,
// 3 sets logged at 80 kg against 10 reps.

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle, Exercise} */
function recoverySeed(int $minReps = 5, int $maxReps = 30): array
{
    Carbon::setTestNow('2026-09-21 09:00:00');
    $bench = Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => $minReps, 'rep_range_max' => $maxReps,
    ]);
    $user = TrainSeed::user();

    return [$user, TrainSeed::mesocycle($user), $bench];
}

/** @return array{list<?float>, ?string} week 3 day 1's planned bench weights in kg, and the note the screen shows */
function recoveryOf(User $user, Mesocycle $mesocycle, Exercise $bench, int $week = 3): array
{
    $snapshot = WorkoutSnapshot::load($user, $mesocycle->id, $week, 1);

    return [
        array_map(fn (WorkoutSetRow $set) => $set->targetKg, $snapshot->plannedBlocks()[0]->sets),
        $snapshot->recoveryNote($bench, 3),
    ];
}

function painAfter(WorkoutSession $session, Exercise $exercise, string $pain): void
{
    ExerciseFeedback::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'joint_pain' => $pain]);
}

it('PROG-051 a muscle that missed its targets two sessions running repeats last week\'s weight, with the note on the exercise', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 7);
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);
    $note = 'Chest missed its rep targets 2 sessions running. Hold sets and repeat the weight to recover.';

    expect(recoveryOf($user, $mesocycle, $bench))->toBe([[80.0, 80.0, 80.0], $note])
        ->and(WorkoutPayload::build(WorkoutSnapshot::load($user, $mesocycle->id, 3, 1))->exercises[0]->recovery_note?->text)->toBe($note);
});

it('PROG-051 a muscle that missed only once, or whose miss was in a skipped session, is not flagged', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 7)->forceFill(['is_skipped' => true])->save();
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);

    [$weights, $note] = recoveryOf($user, $mesocycle, $bench);

    // The plain PROG-021 rise: 2% on 80 kg, to the 2.5 kg step. A flag would hold 80.
    expect([$weights, $note])->toBe([[82.5, 82.5, 82.5], null]);
});

it('PROG-018 a lot of joint pain last week cuts the weight 10%, rounded down to the step (72 kg to 70)', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00'), $bench, 'aLot');

    expect(recoveryOf($user, $mesocycle, $bench))->toBe([[70.0, 70.0, 70.0], RecoveryLoad::JOINT_PAIN_NOTE]);
});

it('PROG-018 moderate pain, or pain in a session of another day or week, does not cut', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00'), $bench, 'aLot');
    $otherDay = TrainSeed::trained($mesocycle, 2, 2, '2026-09-17');
    WorkoutSet::query()->where('workout_session_id', $otherDay->id)->update(['exercise_id' => $bench->id]);
    painAfter($otherDay, $bench, 'aLot');
    painAfter(WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00'), $bench, 'moderate');

    expect(recoveryOf($user, $mesocycle, $bench)[1])->toBeNull();
});

it('PROG-053 reps below the range two sessions running take the target down one step', function () {
    [$user, $mesocycle, $bench] = recoverySeed(minReps: 12, maxReps: 20);
    WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 9);
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 9);

    [$weights, $note] = recoveryOf($user, $mesocycle, $bench);

    // One 2.5 kg step under the planned 82.5.
    expect([$weights, $note])->toBe([[80.0, 80.0, 80.0], 'Your reps were below 12 to 20 on most sets for 2 sessions, so the weight goes down one step.']);
});

it('PROG-051 the held weight is last week\'s, in the pounds the user sees', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    Profile::query()->where('user_id', $user->id)->update(['weight_unit' => 'lb']);
    WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 7);
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);

    // 80 kg is 176.4 lb, the weight held; read as kilograms it would stay 80.
    expect(round((float) recoveryOf($user, $mesocycle, $bench)[0][0] * 2.20462, 1))->toBe(176.4);
});

it('PROG-040 PROG-020 a deload week and week 1 keep the planned targets with no note', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 4, 1, '2026-09-28 17:30:00'), $bench, 'aLot');

    expect(recoveryOf($user, $mesocycle, $bench, week: 5)[1])->toBeNull()
        ->and(recoveryOf($user, $mesocycle, $bench, week: 1)[1])->toBeNull();
});

it('EX-009 the latest two answers across days and mesocycles suggest a swap when both hurt, and the screen shows it', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00'), $bench, 'none');
    $otherDay = TrainSeed::trained($mesocycle, 1, 2, '2026-09-10');
    painAfter($otherDay, $bench, 'moderate');
    $session = WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00');
    $suggestion = fn () => WorkoutSnapshot::load($user, $mesocycle->id, 2, 1)->swapSuggestion($bench);

    expect($suggestion())->toBeNull();

    painAfter($session, $bench, 'aLot');

    expect($suggestion())->toBe('Bench Press hurt 2 sessions running. Consider swapping it.')
        ->and(WorkoutPayload::build(WorkoutSnapshot::load($user, $mesocycle->id, 2, 1))->exercises[0]->swap_suggestion?->text)
        ->toBe('Bench Press hurt 2 sessions running. Consider swapping it.');
});

it('EX-009 the start time orders the answers before week and day, so an older answer ends no run', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    // Week 2 ran before week 1 here and was stored after it: by start time its Low answer is the oldest, so the run is
    // the two A lots; by row order the Low would end it.
    painAfter(WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00'), $bench, 'aLot');
    painAfter(WorkoutSeed::started($mesocycle, 2, 1, '2026-09-01 17:30:00'), $bench, 'low');
    painAfter(WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00'), $bench, 'aLot');

    expect(WorkoutSnapshot::load($user, $mesocycle->id, 3, 1)->swapSuggestion($bench))->not->toBeNull();
});
