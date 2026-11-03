<?php

use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// OFF-007: a start or finish replayed from the outbox records the instant the user acted, not the server clock.

afterEach(fn () => Carbon::setTestNow());

function workoutAt(User $user, string $name, string $workoutId, ?string $occurredAt): mixed
{
    $headers = ['Idempotency-Key' => (string) Str::ulid()];

    if ($occurredAt !== null) {
        $headers['X-BB-Occurred-At'] = $occurredAt;
    }

    return test()->actingAs($user)->postJson("/capabilities/{$name}", ['workout_id' => $workoutId], $headers);
}

it('OFF-007 WO-010 workout_start replayed with an older X-BB-Occurred-At records when the user started', function () {
    Carbon::setTestNow('2026-09-23 09:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    workoutAt($user, 'workout_start', WorkoutIds::workout($mesocycle->id, 3, 1), '2026-09-21T17:40:00Z')->assertOk();

    expect(WorkoutSession::query()->sole()->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:40');
});

it('OFF-007 workout_start stores an X-BB-Occurred-At with an offset as its UTC instant', function () {
    Carbon::setTestNow('2026-09-23 09:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    workoutAt($user, 'workout_start', WorkoutIds::workout($mesocycle->id, 3, 1), '2026-09-22T03:40:00+10:00')->assertOk();

    expect(WorkoutSession::query()->sole()->getRawOriginal('actual_start_at'))->toBe('2026-09-21 17:40:00');
});

it('OFF-007 WO-010 workout_finish replayed with an older X-BB-Occurred-At records when the user finished', function () {
    Carbon::setTestNow('2026-09-21 19:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');
    WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), ['pump' => 'low', 'volume' => 'notEnough']);
    Carbon::setTestNow('2026-09-23 09:00:00');

    workoutAt($user, 'workout_finish', WorkoutSeed::id($session), '2026-09-21T18:30:00Z')->assertOk();

    expect($session->fresh()->actual_end_at->format('Y-m-d H:i'))->toBe('2026-09-21 18:30');
});

it('OFF-007 workout_start with no X-BB-Occurred-At records the request clock', function () {
    Carbon::setTestNow('2026-09-21 17:40:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    workoutAt($user, 'workout_start', WorkoutIds::workout($mesocycle->id, 3, 1), null)->assertOk();

    expect(WorkoutSession::query()->sole()->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:40');
});
