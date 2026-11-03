<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;

// The HTTP seam of mesocycle_length_set. The rules are unit tests (MesocycleCalendarTest, SetMesocycleLengthTest).

afterEach(fn () => Carbon::setTestNow());

function lengthSetOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/mesocycle_length_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('MESO-042 refuses mesocycle_length_set when signed out', function () {
    lengthSetOver(null, ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5', 'weeks' => 6, 'expected_weeks' => 5])->assertUnauthorized();
});

it('MESO-042 answers 404 for another user\'s mesocycle, and for a draft', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());
    $user = TrainSeed::user();
    $draft = TrainSeed::mesocycle($user, ['is_draft' => true]);

    lengthSetOver(TrainSeed::user(), ['mesocycle_id' => $theirs->id, 'weeks' => 6, 'expected_weeks' => 5])->assertNotFound();
    lengthSetOver($user, ['mesocycle_id' => $draft->id, 'weeks' => 6, 'expected_weeks' => 5])->assertNotFound();
});

it('MESO-042 answers 422 naming weeks when it is past eight', function () {
    $user = TrainSeed::user();

    lengthSetOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'weeks' => 9, 'expected_weeks' => 5])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'weeks');
});

it('MESO-042 answers 422 in plain words for a length the mesocycle cannot take now', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();

    lengthSetOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'weeks' => 3, 'expected_weeks' => 5])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This mesocycle is in week 3, so it can run 4 to 8 weeks.');
});

it('MESO-042 answers 422 naming expected_weeks when it is left out', function () {
    $user = TrainSeed::user();

    lengthSetOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'weeks' => 6])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'expected_weeks');
});

it('MESO-042 answers 422 in plain words when the length moved since the screen was loaded, and changes nothing', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['weeks' => 6]);

    lengthSetOver($user, ['mesocycle_id' => $mesocycle->id, 'weeks' => 4, 'expected_weeks' => 5])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This mesocycle\'s length changed to 6 weeks since the screen was loaded, so nothing was changed.');

    expect($mesocycle->fresh()->weeks)->toBe(6);
});

it('MESO-042 changes the length and says which reads changed', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    lengthSetOver($user, ['mesocycle_id' => $mesocycle->id, 'weeks' => 6, 'expected_weeks' => 5])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['train_show{}', "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}"])
        ->assertJsonPath('data.toast', 'Mesocycle is now 6 weeks');

    expect($mesocycle->fresh()->weeks)->toBe(6);
});
