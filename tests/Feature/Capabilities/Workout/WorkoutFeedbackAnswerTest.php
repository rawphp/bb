<?php

use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Workout\WorkoutReads;
use App\Models\ExerciseFeedback;
use App\Models\MuscleFeedback;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// The HTTP seam of workout_feedback_answer. The rules are unit tests (FeedbackSheetsTest, AnswerWorkoutFeedbackTest).

afterEach(fn () => Carbon::setTestNow());

function feedbackOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/workout_feedback_answer', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function feedbackInput(string $workoutId, int $exerciseId, array $input = []): array
{
    return [
        'workout_id' => $workoutId, 'muscle_group' => 'chest', 'pump' => 'low', 'volume' => 'notEnough',
        'joint_pain' => [['exercise_id' => (string) $exerciseId, 'answer' => 'none']], ...$input,
    ];
}

it('CAP-013 FB-009 workout_feedback_answer is reversible and not destructive', function () {
    $definition = app(CapabilityRegistry::class)->get('workout_feedback_answer');

    expect(HostDefinition::isReversible($definition))->toBeTrue()
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(WorkoutReads::AFFECTS);
});

it('FB-001 refuses workout_feedback_answer when signed out', function () {
    feedbackOver(null, feedbackInput('01JB6Q3V8T2K9WZ4M5N7P1R3S5', 1))->assertUnauthorized();
});

it('FB-001 answers 404 for another user\'s workout', function () {
    Carbon::setTestNow('2026-09-21 18:30:00');
    $theirs = TrainSeed::mesocycle(TrainSeed::user());
    $session = WorkoutSeed::started($theirs, 3, 1, '2026-09-21 17:30:00');

    feedbackOver(TrainSeed::user(), feedbackInput(WorkoutSeed::id($session), WorkoutSeed::exerciseId($theirs, 1)))->assertNotFound();

    expect(MuscleFeedback::query()->count())->toBe(0);
});

it('FB-004 answers 422 naming pump when it is not one of the answers', function () {
    feedbackOver(TrainSeed::user(), feedbackInput('01JB6Q3V8T2K9WZ4M5N7P1R3S5', 1, ['pump' => 'huge']))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'pump');
});

it('FB-001 FB-009 saves the sheet, names the screens it changed, and undo takes it back', function () {
    Carbon::setTestNow('2026-09-21 18:30:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-21 17:30:00');

    $response = feedbackOver($user, feedbackInput(WorkoutSeed::id($session), WorkoutSeed::exerciseId($mesocycle, 1)))
        ->assertOk()
        ->assertJsonPath('data.toast', null);
    expect($response->json('data.invalidates'))->toContain(
        "workout_show{\"day\":1,\"mesocycle_id\":\"{$mesocycle->id}\",\"week\":3}",
        'train_show{}',
        "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}",
    )->and(MuscleFeedback::query()->count())->toBe(1);

    test()->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $response->json('data.undo')], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(MuscleFeedback::query()->count())->toBe(0)
        ->and(ExerciseFeedback::query()->count())->toBe(0);
});

it('FB-008 answers a refusal in plain words', function () {
    Carbon::setTestNow('2026-10-05 18:30:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $session = WorkoutSeed::started($mesocycle, 5, 1, '2026-10-05 17:30:00');

    feedbackOver($user, feedbackInput(WorkoutSeed::id($session), WorkoutSeed::exerciseId($mesocycle, 1)))
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'No feedback is asked in a deload week.');
});
