<?php

use App\Models\Phase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// phase_transition_complete over HTTP: the seam only. The rules are CompletePhaseTransitionTest's and
// TransitionPayloadTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

const ENDING_CUT = '01JC1G0000000000000000000A';

function phaseTransitionComplete(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);
    $input += ['phase_id' => ENDING_CUT, 'next_phase_id' => '01JC1F0000000000000000000A', 'choice' => 'maintenance', 'gain_first' => false, 'weeks' => 5, 'template' => 'resensitization'];

    return $request->postJson('/capabilities/phase_transition_complete', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function cutEnding(): User
{
    $user = TrainSeed::user();
    Phase::factory()->for($user)->create(['id' => ENDING_CUT, 'type' => 'fatLoss', 'start_date' => '2026-08-31', 'end_date' => '2026-10-19']);

    return $user;
}

it('PH-030 refuses phase_transition_complete when signed out', function () {
    phaseTransitionComplete(null, [])->assertUnauthorized();
});

it('PH-030 answers 404 for another user\'s phase', function () {
    PlanSeed::world();

    phaseTransitionComplete(cutEnding(), ['phase_id' => W::HOLD])->assertNotFound();
});

it('CAP-019 answers 404 for a next phase id another user owns, and saves nothing', function () {
    PlanSeed::world();
    $user = cutEnding();

    phaseTransitionComplete($user, ['next_phase_id' => W::CUT])->assertNotFound();
    expect(Phase::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('PH-036 answers 422 naming template when the option does not offer it, and again when the same key is sent twice', function () {
    $user = cutEnding();
    $send = fn () => test()->actingAs($user)->postJson('/capabilities/phase_transition_complete', [
        'phase_id' => ENDING_CUT, 'next_phase_id' => '01JC1F0000000000000000000A', 'choice' => 'maintenance', 'gain_first' => false, 'weeks' => 5, 'template' => 'standard',
    ], ['Idempotency-Key' => '01JC1H0000000000000000000A']);

    $send()->assertStatus(422)->assertJsonPath('error.violations.0.field', 'template');
    $send()->assertStatus(422)->assertJsonPath('error.violations.0.field', 'template');
});

it('PH-030 saves what comes next and invalidates the ending phase and the new one', function () {
    phaseTransitionComplete(cutEnding(), [])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Maintenance comes next')
        ->assertJsonFragment(['phase_show{"phase_id":"'.ENDING_CUT.'"}'])
        ->assertJsonFragment(['phase_show{"phase_id":"01JC1F0000000000000000000A"}']);
});
