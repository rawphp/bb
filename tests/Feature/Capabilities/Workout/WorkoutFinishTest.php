<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// The HTTP seam of workout_finish. The rules are unit tests (FeedbackSheetsTest, ProgressionApplyTest,
// FinishWorkoutTest).

afterEach(fn () => Carbon::setTestNow());

function finishOver(?User $user, array $input, ?string $key = null): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/workout_finish', $input, ['Idempotency-Key' => $key ?? (string) Str::ulid()]);
}

it('CAP-013 UI-010 workout_finish is reversible and not destructive', function () {
    $definition = app(CapabilityRegistry::class)->get('workout_finish');

    expect(HostDefinition::isReversible($definition))->toBeTrue()
        ->and(HostDefinition::isDestructive($definition))->toBeFalse();
});

it('WO-010 refuses workout_finish when signed out', function () {
    finishOver(null, ['workout_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5'])->assertUnauthorized();
});

it('WO-010 answers 404 for another user\'s workout, and finishes nothing', function () {
    Carbon::setTestNow('2026-09-21 18:30:00');
    $theirs = TrainSeed::mesocycle(TrainSeed::user());
    $session = WorkoutSeed::started($theirs, 3, 1, '2026-09-21 17:30:00');

    finishOver(TrainSeed::user(), ['workout_id' => WorkoutSeed::id($session)])->assertNotFound();

    expect($session->fresh()->actual_end_at)->toBeNull();
});

it('CAP-019 answers 422 naming workout_id when it is not a ULID', function () {
    finishOver(TrainSeed::user(), ['workout_id' => 'not-a-ulid'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'workout_id');
});

it('WO-040 answers 422 with the banner\'s words and the muscle while feedback is unanswered', function () {
    Carbon::setTestNow('2026-09-21 18:30:00');
    $user = TrainSeed::user();
    $session = WorkoutSeed::started(TrainSeed::mesocycle($user), 3, 1, '2026-09-21 17:30:00');

    finishOver($user, ['workout_id' => WorkoutSeed::id($session)])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Your feedback is required for chest.');
});

it('WO-010 SCH-054 OFF-023 UI-010 finishes the workout, re-targets today, and undo puts the session, sets and meals back', function () {
    Carbon::setTestNow('2026-09-21 19:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');
    WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), ['pump' => 'low', 'volume' => 'notEnough']);
    $day = SheetSeed::day($user, '2026-09-21');
    $before = [$day->fresh()->only(['kcal', 'carbs_g', 'meals']), $mesocycle->fresh()->days];
    $key = (string) Str::ulid();

    $response = finishOver($user, ['workout_id' => WorkoutSeed::id($session)], $key)
        ->assertOk()
        ->assertJsonPath('data.toast', "Chest +2 sets next week\nMeals updated for your 5:30 PM workout");
    expect($response->json('data.invalidates'))->toContain(
        "workout_show{\"day\":1,\"mesocycle_id\":\"{$mesocycle->id}\",\"week\":3}",
        'today_show{"date":"2026-09-21"}', 'today_show{}', 'week_show{}', 'train_show{}',
        "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}",
    )->and($session->fresh()->actual_end_at->format('H:i'))->toBe('19:00');

    // OFF-023: the outbox replays a finish under the same key; it answers as before and finishes nothing twice.
    finishOver($user, ['workout_id' => WorkoutSeed::id($session)], $key)->assertOk()->assertJsonPath('data.undo', $response->json('data.undo'));

    test()->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $response->json('data.undo')], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect($session->fresh()->actual_end_at)->toBeNull()
        ->and([DayTarget::query()->find($day->id)->only(['kcal', 'carbs_g', 'meals']), $mesocycle->fresh()->days])->toEqual($before);
});
