<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// phase_add over HTTP: the seam only. The rules are AddPhaseTest's and NewPhasePayloadTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function phaseAdd(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/phase_add', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('PLAN-006 refuses phase_add when signed out', function () {
    phaseAdd(null, ['phase_id' => '01JC1D0000000000000000000A', 'type' => 'maintenance', 'weeks' => 4, 'rate_percent_per_week' => null])->assertUnauthorized();
});

it('CAP-019 answers 404 for a phase id another user owns', function () {
    PlanSeed::world();

    phaseAdd(TrainSeed::user(), ['phase_id' => W::CUT, 'type' => 'maintenance', 'weeks' => 4, 'rate_percent_per_week' => null])->assertNotFound();
});

it('PLAN-006 answers 422 naming phase_id when it is not a ULID', function () {
    [$user] = PlanSeed::world();

    phaseAdd($user, ['phase_id' => 'x', 'type' => 'maintenance', 'weeks' => 4, 'rate_percent_per_week' => null])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'phase_id');
});

it('PLAN-006 adds the phase and invalidates the plan reads and its phase', function () {
    [$user] = PlanSeed::world();

    phaseAdd($user, ['phase_id' => '01JC1D0000000000000000000A', 'type' => 'maintenance', 'weeks' => 4, 'rate_percent_per_week' => null])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Maintenance added to your plan')
        ->assertJsonFragment(['phase_show{"phase_id":"01JC1D0000000000000000000A"}']);
});
