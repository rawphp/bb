<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;

// The HTTP seam of mesocycle_active_rest_take. The rules are unit tests (TakeActiveRestTest, TrainPayloadTest).

afterEach(fn () => Carbon::setTestNow());

function activeRestOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/mesocycle_active_rest_take', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('MESO-064 refuses mesocycle_active_rest_take when signed out', function () {
    activeRestOver(null, ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5'])->assertUnauthorized();
});

it('MESO-064 answers 404 for another user\'s mesocycle', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());

    activeRestOver(TrainSeed::user(), ['mesocycle_id' => $theirs->id])->assertNotFound();
});

it('MESO-064 answers 422 naming mesocycle_id when it is missing', function () {
    activeRestOver(TrainSeed::user(), [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'mesocycle_id');
});

it('MESO-064 takes the recommended active rest, and train_show moves the next start a week later', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);

    activeRestOver($user, ['mesocycle_id' => $mesocycle->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['train_show{}', "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}"]);

    $this->actingAs($user)->postJson('/capabilities/train_show')
        ->assertJsonPath('data.current.next_mesocycle.start_date', '2026-10-19')
        ->assertJsonPath('data.current.next_mesocycle.active_rest.tone', 'good');
});

it('MESO-064 a copy waiting for the old start moves too, and its read is invalidated', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);
    $draft = TrainSeed::mesocycle($user, ['is_draft' => true, 'route' => 'copy_previous', 'start_date' => '2026-10-12']);

    activeRestOver($user, ['mesocycle_id' => $mesocycle->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'train_show{}',
            "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}",
            "mesocycle_show{\"mesocycle_id\":\"{$draft->id}\"}",
        ]);

    expect($draft->fresh()->start_date->format('Y-m-d'))->toBe('2026-10-19');
});
