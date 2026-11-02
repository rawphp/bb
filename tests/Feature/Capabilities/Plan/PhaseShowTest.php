<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\PlanSeed;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// The HTTP seam of phase_show and its screen fixtures. The rules are unit tests (PhasePayloadTest).

afterEach(fn () => Carbon::setTestNow());

it('PLAN-004 refuses phase_show when signed out', function () {
    $this->postJson('/capabilities/phase_show', ['phase_id' => W::CUT])->assertUnauthorized();
});

it('PLAN-004 answers 404 for another user\'s phase', function () {
    PlanSeed::world();

    $this->actingAs(TrainSeed::user())->postJson('/capabilities/phase_show', ['phase_id' => W::CUT])->assertNotFound();
});

it('PLAN-004 answers 422 naming phase_id when it is missing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/phase_show', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'phase_id');
});

it('PLAN-004 shows the running phase in the fixture\'s shape, with its event warning and offer', function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    [$user] = PlanSeed::world(event: '2026-11-15');

    $data = $this->actingAs($user)->postJson('/capabilities/phase_show', ['phase_id' => W::CUT])->assertOk()->json('data');

    assertMatchesScreenFixture('phase_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('phase_show.warning'), $data))->toBe([])
        ->and($data['progress_text'])->toBe('Week 7 of 12')
        ->and($data['start_weight_text'])->toBe('90.0 kg')
        ->and($data['event_offer']['weeks'])->toBe(11);
});

it('PLAN-004 shows a phase that has not started in the empty fixture\'s shape', function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    [$user] = PlanSeed::world();

    $data = $this->actingAs($user)->postJson('/capabilities/phase_show', ['phase_id' => W::HOLD])->assertOk()->json('data');

    assertMatchesScreenFixture('phase_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('phase_show.empty'), $data))->toBe([])
        ->and($data['progress_text'])->toBe('Starts in 6 weeks')
        ->and($data['can_end'])->toBeFalse();
});
