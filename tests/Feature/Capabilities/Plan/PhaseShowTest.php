<?php

use App\Models\User;
use App\Models\WeighIn;
use App\Support\Plan\PlanSnapshot;
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

it('SET-012 a phase\'s start weight is the trend on its first local day', function (string $zone, string $startWeight) {
    Carbon::setTestNow('2026-10-14 10:00:00');
    [$user] = PlanSeed::world();
    $user->forceFill(['time_zone' => $zone])->save();
    WeighIn::query()->where('user_id', $user->id)->delete();
    WeighIn::factory()->for($user)->create(['weight_kg' => 90.0, 'weighed_at' => '2026-08-30 21:00:00']);
    WeighIn::factory()->for($user)->create(['weight_kg' => 91.0, 'weighed_at' => '2026-08-31 21:00:00']);

    $data = $this->actingAs($user)->postJson('/capabilities/phase_show', ['phase_id' => W::CUT])->assertOk()->json('data');

    expect($data['start_weight_text'])->toBe($startWeight);
})->with([
    'Brisbane' => ['Australia/Brisbane', '90.0 kg'],
    'UTC' => ['UTC', '90.1 kg'],
]);

it('SET-012 a phase\'s lead weigh-ins start at the user\'s local midnight', function (string $zone, array $read) {
    Carbon::setTestNow('2026-10-14 10:00:00');
    [$user] = PlanSeed::world();
    $user->forceFill(['time_zone' => $zone])->save();
    WeighIn::query()->where('user_id', $user->id)->delete();
    foreach (['2026-07-01 13:00:00', '2026-07-01 21:00:00', '2026-07-02 06:00:00'] as $at) {
        WeighIn::factory()->for($user)->create(['weight_kg' => 90.0, 'weighed_at' => $at]);
    }

    $weighIns = PlanSnapshot::load($user)->weighInsSince(PlanSnapshot::day('2026-07-02'));

    expect($weighIns->map(fn (WeighIn $w) => $w->weighed_at->format('Y-m-d H:i:s'))->all())->toBe($read);
})->with([
    'Brisbane' => ['Australia/Brisbane', ['2026-07-01 21:00:00', '2026-07-02 06:00:00']],
    'UTC' => ['UTC', ['2026-07-02 06:00:00']],
]);

it('SET-012 a phase\'s event countdown counts from the user\'s local day', function (string $zone, string $eventText) {
    Carbon::setTestNow('2026-10-14 15:00:00');
    [$user] = PlanSeed::world(event: '2026-11-15');
    $user->forceFill(['time_zone' => $zone])->save();

    $phase = $this->actingAs($user)->postJson('/capabilities/phase_show', ['phase_id' => W::CUT])->assertOk()->json('data');
    $plan = $this->actingAs($user)->postJson('/capabilities/plan_show')->assertOk()->json('data');

    expect($phase['event_text'])->toBe($eventText)
        ->and($plan['phases'][0]['event_text'])->toBe($eventText);
})->with([
    'Brisbane' => ['Australia/Brisbane', '31 days to your event'],
    'UTC' => ['UTC', '32 days to your event'],
]);
