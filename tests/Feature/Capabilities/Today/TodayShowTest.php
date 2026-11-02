<?php

use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;

// The HTTP seam of today_show and its screen fixtures. The rules are unit tests (TodayPayloadTest).

afterEach(fn () => Carbon::setTestNow());

it('SCH-001 refuses today_show when signed out', function () {
    $this->postJson('/capabilities/today_show')->assertUnauthorized();
});

it('SCH-020 answers 422 for a date that is not a date', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/today_show', ['date' => 'next tuesday'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'date');
});

it('SCH-001 SCH-002 SCH-003 SCH-004 SCH-005 SCH-006 SCH-011 shows a seeded day with the clock fixed', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    $mesocycle = TodaySeed::mesocycle($user);
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, $mesocycle);

    $data = $this->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');

    assertMatchesScreenFixture('today_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('today_show.warning'), $data))->toBe([])
        ->and($data['title'])->toBe('Wednesday, Sep 23')
        ->and(array_column($data['timeline'], 'kind'))->toBe(['weigh_in', 'meal', 'busy', 'meal', 'workout', 'other_activity', 'meal'])
        ->and($data['meals'][0]['planned_text'])->toBe('Oats 80 g')
        ->and($data['meals'][1]['check_in']['state'])->toBe('on_track')
        ->and($data['workouts'][0]['title'])->toBe('Week 2 · Day 2 · Lower A')
        ->and($data['weigh_ins'][0]['weight_text'])->toBe('82.4 kg')
        ->and($data['steps'])->toBe(['target_text' => 'Step count target 6-8k', 'actual_text' => '5,432 steps'])
        ->and($data['totals']['calories']['value_text'])->toBe('2454/2600')
        ->and($data['off_plan'][0]['name'])->toBe('Office cake');
});

it('SCH-003 SCH-020 opens the day the date names', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    $mesocycle = TodaySeed::mesocycle($user);
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, $mesocycle);

    $data = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-09-21'])->assertOk()->json('data');

    assertMatchesScreenFixture('today_show', $data);
    expect($data['date'])->toBe('2026-09-21')
        ->and($data['workouts'][0]['state'])->toBe('done')
        ->and($data['workouts'][0]['completed']['text'])->toBe('1 of 4 sets completed');
});

it('MESO-060 shows the next-mesocycle prompt in the deload week', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    $user = TodaySeed::user();
    $mesocycle = TodaySeed::mesocycle($user);
    TodaySeed::targets($user);

    $data = $this->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');

    assertMatchesScreenFixture('today_show', $data);
    expect($data['next_mesocycle']['mesocycle_id'])->toBe($mesocycle->id)
        ->and($data['next_mesocycle']['start_text'])->toBe('Starts Saturday, Oct 10')
        ->and($data['totals']['day_type']['text'])->toBe('Deload');
});

it('ONB-031 shows an empty day to a user without a plan, and a banner before the plan starts', function () {
    Carbon::setTestNow('2026-09-10 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::targets($user);

    $fresh = $this->actingAs(User::factory()->create())->postJson('/capabilities/today_show')->assertOk()->json('data');
    $before = $this->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');

    assertMatchesScreenFixture('today_show', $fresh);
    assertMatchesScreenFixture('today_show', $before);
    expect(ScreenFixture::differences(ScreenFixture::load('today_show.empty'), $before))->toBe([])
        ->and($fresh['timeline'])->toBe([])
        ->and($fresh['schedule'])->toBeNull()
        ->and($before['banners'][0]['text'])->toBe('Your plan starts Sat, Sep 12')
        ->and($before['timeline'])->toBe([]);
});

it('CAP-020 shows only the signed-in user\'s rows', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $other = TodaySeed::user();
    TodaySeed::targets($other);
    $user = User::factory()->create();

    $data = $this->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');

    expect($data['totals'])->toBeNull()
        ->and($data['meals'])->toBe([])
        ->and(DayTarget::query()->where('user_id', $other->id)->count())->toBe(28);
});
