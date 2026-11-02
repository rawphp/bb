<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\PlanSeed;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// The HTTP seam of plan_show and its screen fixtures. The rules are unit tests (PlanPayloadTest).

afterEach(fn () => Carbon::setTestNow());

it('PLAN-003 refuses plan_show when signed out', function () {
    $this->postJson('/capabilities/plan_show')->assertUnauthorized();
});

it('PLAN-001 answers 422 naming a field plan_show does not take', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/plan_show', ['phase_id' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'phase_id');
});

it('PLAN-001 shows only the user\'s own plan, and an empty one to a user without a plan', function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    PlanSeed::world();

    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/plan_show')->assertOk()->json('data');

    assertMatchesScreenFixture('plan_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('plan_show.empty'), $data))->toBe([])
        ->and($data['phases'])->toBe([]);
});

it('PLAN-003 shows the timeline of a seeded plan in the fixture\'s shape', function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    [$user] = PlanSeed::world(event: '2026-11-15');

    $data = $this->actingAs($user)->postJson('/capabilities/plan_show')->assertOk()->json('data');

    assertMatchesScreenFixture('plan_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('plan_show.warning'), $data))->toBe([])
        ->and(array_column($data['phases'], 'id'))->toBe([W::CUT, W::HOLD, W::GAIN])
        ->and(array_column($data['deloads'], 'week'))->toBe([5, 11, 15])
        ->and($data['current']['progress_text'])->toBe('Week 7 of 12')
        ->and($data['event_countdown'])->toBe('32 days to your event');
});

it('PLAN-005 a draft mesocycle has no band and no deload on the timeline', function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    [$user] = PlanSeed::world();
    TrainSeed::mesocycle($user, ['name' => 'Draft', 'is_draft' => true, 'start_date' => '2026-12-21', 'weeks' => 5]);

    $data = $this->actingAs($user)->postJson('/capabilities/plan_show')->assertOk()->json('data');

    expect(array_column($data['mesocycles'], 'id'))->toBe([W::MESO1, W::MESO2, W::MESO3])
        ->and(array_column($data['deloads'], 'week'))->toBe([5, 11, 15]);
});
