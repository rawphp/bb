<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;

// The HTTP seam of week_show and its screen fixtures. The rules are unit tests (WeekPayloadTest).

afterEach(fn () => Carbon::setTestNow());

it('SCH-020 refuses week_show when signed out', function () {
    $this->postJson('/capabilities/week_show')->assertUnauthorized();
});

it('SCH-020 answers 422 for a date that is not a date', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/week_show', ['date' => '2026-13-45'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'date');
});

it('SCH-020 SCH-021 SCH-022 SCH-023 shows a seeded week with the clock fixed', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    $mesocycle = TodaySeed::mesocycle($user);
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, $mesocycle);

    $data = $this->actingAs($user)->postJson('/capabilities/week_show')->assertOk()->json('data');

    assertMatchesScreenFixture('week_show', $data);
    expect($data['week_start'])->toBe('2026-09-19')
        ->and($data['heading'])->toBe('WEEK 2')
        ->and(array_column($data['days'], 'has_training'))->toBe([false, false, true, false, true, false, true])
        ->and($data['calendar'])->toBe(['first' => '2026-09-12', 'last' => '2026-10-09'])
        ->and([$data['previous_week'], $data['next_week']])->toBe(['2026-09-12', '2026-09-26']);
});

it('SCH-022 shows the deload week\'s pill', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::mesocycle($user);
    TodaySeed::targets($user);

    $data = $this->actingAs($user)->postJson('/capabilities/week_show', ['date' => '2026-10-06'])->assertOk()->json('data');

    assertMatchesScreenFixture('week_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('week_show.warning'), $data))->toBe([])
        ->and($data['pills'])->toBe([['text' => 'Deload', 'tone' => 'neutral']])
        ->and($data['next_week'])->toBeNull();
});

it('SCH-021 shows an empty week to a user without a plan', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');

    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/week_show')->assertOk()->json('data');

    assertMatchesScreenFixture('week_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('week_show.empty'), $data))->toBe([])
        ->and(array_unique(array_column($data['days'], 'calories_text')))->toBe(['–'])
        ->and($data['heading'])->toBeNull()
        ->and($data['calendar'])->toBeNull();
});

it('CAP-020 shows only the signed-in user\'s rows', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $other = TodaySeed::user();
    TodaySeed::targets($other);

    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/week_show')->assertOk()->json('data');

    expect(array_unique(array_column($data['days'], 'calories_text')))->toBe(['–']);
});
