<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// The HTTP seam of away_week_show. The rules are AwayWeekPayloadTest's and AwayWeeksTest's.

afterEach(fn () => Carbon::setTestNow());

it('SCH-060 refuses away_week_show when signed out', function () {
    $this->postJson('/capabilities/away_week_show', ['week_start' => '2026-09-26'])->assertUnauthorized();
});

it('SCH-060 answers 422 naming week_start when it is not a date', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/away_week_show', ['week_start' => '2026-13-45'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'week_start');
});

it('SCH-060 answers 422 for a week the sheet would not offer', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TrainSeed::phase($user, 'fatLoss', '2026-09-12', '2026-12-05');

    $this->actingAs($user)->postJson('/capabilities/away_week_show', ['week_start' => '2026-09-19'])
        ->assertStatus(422);
});

it('SCH-060 PLAN-025 shows a seeded week with the clock fixed', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::mesocycle($user);
    TrainSeed::phase($user, 'fatLoss', '2026-09-12', '2026-12-05');

    $data = $this->actingAs($user)->postJson('/capabilities/away_week_show', ['week_start' => '2026-09-26'])->assertOk()->json('data');

    assertMatchesScreenFixture('away_week_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('away_week_show'), $data))->toBe([])
        ->and($data['is_editing'])->toBeFalse()
        ->and($data['calories'])->toBe('maintenance')
        ->and($data['shows_keep_phase_end'])->toBeTrue()
        ->and($data['weeks'][0]['label'])->toBe('Week 3 · Sep 26');
});
