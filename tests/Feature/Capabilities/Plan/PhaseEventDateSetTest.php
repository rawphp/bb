<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// phase_event_date_set over HTTP: the seam only. The rules are SetPhaseEventDateTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function phaseEventDateSet(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/phase_event_date_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('PH-043 refuses phase_event_date_set when signed out', function () {
    phaseEventDateSet(null, ['phase_id' => W::HOLD, 'event_date' => '2026-12-19'])->assertUnauthorized();
});

it('PH-043 answers 404 for another user\'s phase', function () {
    PlanSeed::world();

    phaseEventDateSet(TrainSeed::user(), ['phase_id' => W::HOLD, 'event_date' => '2026-12-19'])->assertNotFound();
});

it('PH-043 answers 422 naming event_date when it is not a date', function () {
    [$user] = PlanSeed::world();

    phaseEventDateSet($user, ['phase_id' => W::HOLD, 'event_date' => 'soon'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'event_date');
});

it('PH-043 sets the event and invalidates the phase', function () {
    [$user] = PlanSeed::world();

    phaseEventDateSet($user, ['phase_id' => W::HOLD, 'event_date' => '2026-12-19'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Event date set')
        ->assertJsonFragment(['phase_show{"phase_id":"'.W::HOLD.'"}']);
});
