<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\LoadRules;
use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Progression\PlannedLoad;
use App\Domain\Progression\RecoveryLoad;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workouts\SessionRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// The recovery flags on a session's targets (PROG-018, PROG-051) and the swap suggestion (EX-009), gathered from the
// stored sets and feedback, from rp-clone's WorkoutRecoveryTests. The rules themselves are RecoveryLoadTest and
// JointPainSwapTest. Meso 2's Upper A on Monday: Bench Press for chest, 3 sets at 80 kg aiming for 10 reps.

uses(RefreshDatabase::class);

/** @return array{User, Mesocycle, Exercise} */
function recoverySeed(): array
{
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    return [$user, $mesocycle, Exercise::query()->findOrFail(WorkoutSeed::exerciseId($mesocycle, 1))];
}

/** Week 3's targets: 3 sets at 82.5 kg for 10, barbell steps of 2.5 kg. */
function recoveryOf(User $user, WorkoutSession $session, Exercise $bench, bool $deload = false): array
{
    $planned = array_fill(0, 3, new PlannedLoad(82.5, 10));
    $rules = new LoadRules(LoadType::BarbellOrSmithMachine, WeightUnit::Kg, false, null, new ClosedRange(5, 30));
    $result = SessionRecovery::load($user, $session, WeightUnit::Kg)->recovery($bench, $planned, $deload ? null : 1, $deload, false, $rules);

    return [array_map(fn (PlannedLoad $load) => $load->weight, $result->targets), $result->note];
}

function painAfter(WorkoutSession $session, Exercise $exercise, string $pain): void
{
    ExerciseFeedback::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'joint_pain' => $pain]);
}

it('PROG-051 a muscle that missed its targets two sessions running repeats last week\'s weight, with the note', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 7);
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00', reps: null);

    expect(recoveryOf($user, $session, $bench))
        ->toBe([[80.0, 80.0, 80.0], 'Chest missed its rep targets 2 sessions running. Hold sets and repeat the weight to recover.']);
});

it('PROG-051 a muscle that missed only once, or whose miss was in a skipped session, is not flagged', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    $skipped = WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 7);
    $skipped->forceFill(['is_skipped' => true])->save();
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00', reps: null);

    expect(recoveryOf($user, $session, $bench))->toBe([[82.5, 82.5, 82.5], null]);
});

it('PROG-018 a lot of joint pain last week cuts the weight 10%, rounded down to the step (72 kg to 70)', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00'), $bench, 'aLot');
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00', reps: null);

    expect(recoveryOf($user, $session, $bench))->toBe([[70.0, 70.0, 70.0], RecoveryLoad::JOINT_PAIN_NOTE]);
});

it('PROG-018 moderate pain, or pain in a session of another day, does not cut', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00'), $bench, 'aLot');
    painAfter(WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00'), $bench, 'moderate');
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00', reps: null);

    expect(recoveryOf($user, $session, $bench))->toBe([[82.5, 82.5, 82.5], null]);
});

it('PROG-040 PROG-020 a deload week and week 1 keep the planned targets with no note', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 4, 1, '2026-09-28 17:30:00'), $bench, 'aLot');

    expect(recoveryOf($user, WorkoutSeed::started($mesocycle, 5, 1, '2026-10-05 17:30:00', reps: null), $bench, deload: true))
        ->toBe([[82.5, 82.5, 82.5], null])
        ->and(recoveryOf($user, WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: null), $bench))
        ->toBe([[82.5, 82.5, 82.5], null]);
});

it('EX-009 the latest two answers across days and mesocycles suggest a swap when both hurt', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    painAfter(WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00'), $bench, 'none');
    $last = WorkoutSeed::started($mesocycle, 1, 2, '2026-09-10 17:30:00');
    painAfter($last, $bench, 'moderate');
    $session = WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00');
    $recovery = fn () => SessionRecovery::load($user, $session, WeightUnit::Kg)->swapSuggestion($bench);

    expect($recovery())->toBeNull();

    painAfter($session, $bench, 'aLot');

    expect($recovery())->toBe('Bench Press hurt 2 sessions running. Consider swapping it.');
});

it('EX-009 the start time orders the answers before week and day, so an older answer does not count', function () {
    [$user, $mesocycle, $bench] = recoverySeed();
    // Week 2 ran before week 1's session here: its Low answer is the oldest, so the run is the two A lots.
    painAfter(WorkoutSeed::started($mesocycle, 2, 1, '2026-09-01 17:30:00'), $bench, 'low');
    painAfter(WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00'), $bench, 'aLot');
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');
    painAfter($session, $bench, 'aLot');

    expect(SessionRecovery::load($user, $session, WeightUnit::Kg)->swapSuggestion($bench))->not->toBeNull();
});
