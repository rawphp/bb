<?php

use App\Models\DayTarget;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;
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

/** Week 3 day 1, planned for 60 minutes from 17:30 on Monday 21 September, started then and ready to finish. */
function finishableAt1730(User $user): WorkoutSession
{
    Carbon::setTestNow('2026-09-21 17:30:00');
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');
    WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), ['pump' => 'low', 'volume' => 'notEnough']);

    return $session;
}

/** A day's targets and its meals' targets, without the ids the meals are given. */
function targetsOf(DayTarget $day): array
{
    $day = $day->fresh();

    return [$day->only(['kcal', 'protein_g', 'fat_g', 'carbs_g']), array_map(fn (array $meal) => array_diff_key($meal, ['id' => true]), $day->meals)];
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

it('OFF-007 workout_finish stores an X-BB-Occurred-At with an offset as its UTC instant', function () {
    Carbon::setTestNow('2026-09-21 19:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');
    WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), ['pump' => 'low', 'volume' => 'notEnough']);
    Carbon::setTestNow('2026-09-23 09:00:00');

    workoutAt($user, 'workout_finish', WorkoutSeed::id($session), '2026-09-22T04:30:00+10:00')->assertOk();

    expect($session->fresh()->getRawOriginal('actual_end_at'))->toBe('2026-09-21 18:30:00');
});

it('OFF-007 workout_start with no X-BB-Occurred-At records the request clock', function () {
    Carbon::setTestNow('2026-09-21 17:40:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    workoutAt($user, 'workout_start', WorkoutIds::workout($mesocycle->id, 3, 1), null)->assertOk();

    expect(WorkoutSession::query()->sole()->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:40');
});

it('OFF-007 SCH-054 a finish replayed later the same day judges the session by when the user finished', function () {
    // 17:30 to 18:30 is the planned hour, so the meals stay; by the 21:30 clock it ran four hours and would re-target them.
    $user = TrainSeed::user();
    $session = finishableAt1730($user);
    $day = SheetSeed::day($user, '2026-09-21');
    $before = targetsOf($day);
    Carbon::setTestNow('2026-09-21 21:30:00');

    $response = workoutAt($user, 'workout_finish', WorkoutSeed::id($session), '2026-09-21T18:30:00Z')->assertOk();

    expect((string) $response->json('data.toast'))->not->toContain('Meals updated')
        ->and($response->json('data.invalidates'))->not->toContain('day_adjust_show{"date":"2026-09-21"}')
        ->and(targetsOf($day))->toBe($before);
});

it('OFF-007 SCH-054 a finish replayed later the same day re-targets the meals for the length the user trained', function () {
    // The user trained 17:30 to 19:00, 30 minutes over. One finish arrives at 19:00, the other replays at 21:30.
    $online = TrainSeed::user();
    $onlineSession = finishableAt1730($online);
    $onlineDay = SheetSeed::day($online, '2026-09-21');
    $replayed = TrainSeed::user();
    $replayedSession = finishableAt1730($replayed);
    $replayedDay = SheetSeed::day($replayed, '2026-09-21', ['meals' => targetsOf($onlineDay)[1]]);
    $before = targetsOf($replayedDay);

    Carbon::setTestNow('2026-09-21 19:00:00');
    $expected = workoutAt($online, 'workout_finish', WorkoutSeed::id($onlineSession), null)->assertOk()->json('data.toast');
    Carbon::setTestNow('2026-09-21 21:30:00');
    $response = workoutAt($replayed, 'workout_finish', WorkoutSeed::id($replayedSession), '2026-09-21T19:00:00Z')->assertOk();

    expect($response->json('data.toast'))->toBe($expected)->toContain('Meals updated for your 5:30 PM workout')
        ->and($response->json('data.invalidates'))->toContain('day_adjust_show{"date":"2026-09-21"}')
        ->and(targetsOf($replayedDay))->not->toBe($before)->toBe(targetsOf($onlineDay));
});

it('OFF-007 SCH-054 a finish replayed on a later day re-targets neither the day it ran nor the server\'s today', function () {
    // SCH-054 re-targets only today's meals still to come: by Wednesday, Monday's dinner is past, and Wednesday did not train.
    $user = TrainSeed::user();
    $session = finishableAt1730($user);
    $monday = SheetSeed::day($user, '2026-09-21');
    $wednesday = SheetSeed::day($user, '2026-09-23');
    $before = [targetsOf($monday), targetsOf($wednesday)];
    Carbon::setTestNow('2026-09-23 09:00:00');

    $response = workoutAt($user, 'workout_finish', WorkoutSeed::id($session), '2026-09-21T19:00:00Z')->assertOk();

    expect((string) $response->json('data.toast'))->not->toContain('Meals updated')
        ->and([targetsOf($monday), targetsOf($wednesday)])->toBe($before)
        ->and($session->fresh()->actual_end_at->format('Y-m-d H:i'))->toBe('2026-09-21 19:00');
});
