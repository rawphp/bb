<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// prebuilt_plan_apply over HTTP: the seam only. The rules are ApplyPrebuiltPlanTest's and NewPhasePayloadTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function prebuiltPlanApply(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/prebuilt_plan_apply', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('PLAN-030 refuses prebuilt_plan_apply when signed out', function () {
    prebuiltPlanApply(null, ['plan' => 'fatLoss', 'first_phase_id' => '01JC1E0000000000000000000A'])->assertUnauthorized();
});

it('CAP-019 answers 404 for a first phase id another user owns', function () {
    PlanSeed::world();

    prebuiltPlanApply(TrainSeed::user(), ['plan' => 'fatLoss', 'first_phase_id' => W::CUT])->assertNotFound();
});

it('PLAN-030 answers 422 naming plan when it is not one of the two', function () {
    [$user] = PlanSeed::world();

    prebuiltPlanApply($user, ['plan' => 'maintenance', 'first_phase_id' => '01JC1E0000000000000000000A'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'plan');
});

it('PLAN-030 adds the plan and invalidates every phase it adds', function () {
    [$user] = PlanSeed::world();

    $invalidates = prebuiltPlanApply($user, ['plan' => 'fatLoss', 'first_phase_id' => '01JC1E0000000000000000000A'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Fat loss plan added')
        ->json('data.invalidates');

    expect(count(array_filter($invalidates, fn (string $key) => str_starts_with($key, 'phase_show'))))->toBe(3);
});
