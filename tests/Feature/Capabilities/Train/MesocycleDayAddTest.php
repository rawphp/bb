<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;

// The HTTP seam of mesocycle_day_add. The rules are unit tests (MesocycleCalendarTest, AddMesocycleDayTest).

afterEach(fn () => Carbon::setTestNow());

function dayAddOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/mesocycle_day_add', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('MESO-045 refuses mesocycle_day_add when signed out', function () {
    dayAddOver(null, ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5', 'day_id' => (string) Str::ulid()])->assertUnauthorized();
});

it('MESO-045 answers 404 for another user\'s mesocycle', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());

    dayAddOver(TrainSeed::user(), ['mesocycle_id' => $theirs->id, 'day_id' => (string) Str::ulid()])->assertNotFound();
});

it('CAP-019 answers 422 naming day_id when it is not a ULID', function () {
    $user = TrainSeed::user();

    dayAddOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'day_id' => 'day-3'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'day_id');
});

it('MESO-045 adds the day the client named', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $day = (string) Str::ulid();

    dayAddOver($user, ['mesocycle_id' => $mesocycle->id, 'day_id' => $day])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['train_show{}', "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}"])
        ->assertJsonPath('data.toast', 'Day added');

    expect(array_column($mesocycle->fresh()->dayRows(), 'id'))->toContain($day);
});
