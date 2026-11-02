<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// The HTTP seam of workout_start. The rules are unit tests (StartWorkoutTest).

afterEach(fn () => Carbon::setTestNow());

function startOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/workout_start', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('WO-010 workout_start is neither destructive nor reversible, and names the reads it changes', function () {
    $definition = app(CapabilityRegistry::class)->get('workout_start');

    expect(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(['workout_show', 'today_show', 'train_show', 'mesocycle_show']);
});

it('WO-010 refuses workout_start when signed out', function () {
    startOver(null, ['workout_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5'])->assertUnauthorized();
});

it('WO-010 answers 404 for another user\'s workout', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());

    startOver(TrainSeed::user(), ['workout_id' => WorkoutIds::workout($theirs->id, 1, 1)])->assertNotFound();

    expect(WorkoutSession::query()->count())->toBe(0);
});

it('WO-010 answers 422 naming the field when the workout id is not a ULID', function () {
    startOver(TrainSeed::user(), ['workout_id' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'workout_id');
});

it('WO-010 starts the session and says which reads changed', function () {
    Carbon::setTestNow('2026-09-21 17:40:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    $response = startOver($user, ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)])->assertOk();

    expect($response->json('data.invalidates'))->toBe([
        'workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":3}',
        'train_show{}',
        "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}",
        'today_show{"date":"2026-09-21"}',
        'today_show{}',
    ])
        ->and(WorkoutSession::query()->sole()->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:40');
});
