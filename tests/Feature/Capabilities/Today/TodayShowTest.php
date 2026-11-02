<?php

use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

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
        ->and($data['meals'][2]['planned_text'])->toBe('Chili 2 servings')
        ->and($data['meals'][1]['check_in']['state'])->toBe('on_track')
        ->and($data['workouts'][0]['title'])->toBe('Week 2 · Day 2 · Lower A')
        ->and($data['weigh_ins'][0]['weight_text'])->toBe('82.4 kg')
        ->and($data['steps'])->toBe(['target_text' => 'Step count target 6-8k', 'actual_text' => '5,432 steps'])
        ->and($data['totals']['calories']['value_text'])->toBe('1914/2600')
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

    // The fresh user has no plan of their own: another user's never shows.

    assertMatchesScreenFixture('today_show', $fresh);
    assertMatchesScreenFixture('today_show', $before);
    expect(ScreenFixture::differences(ScreenFixture::load('today_show.empty'), $before))->toBe([])
        ->and($fresh['timeline'])->toBe([])
        ->and($fresh['banners'])->toBe([])
        ->and($fresh['schedule'])->toBeNull()
        ->and($before['banners'][0]['text'])->toBe('Your plan starts Sat, Sep 12')
        ->and($before['timeline'])->toBe([]);
});

it('SCH-023 keeps a date outside the plan inside it', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::targets($user);

    $late = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-12-25'])->assertOk()->json('data');
    $early = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-01-01'])->assertOk()->json('data');

    expect($late['date'])->toBe('2026-10-09')
        ->and($early['date'])->toBe('2026-09-12');
});

it('CAP-020 shows only the signed-in user\'s rows', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $other = TodaySeed::stranger();
    $user = TodaySeed::user();
    $mine = TodaySeed::mesocycle($user);
    TodaySeed::targets($user);

    $data = $this->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');

    assertMatchesScreenFixture('today_show', $data);
    // With nothing planned or eaten, the totals count every meal at its target.
    expect($data['totals']['calories']['value_text'])->toBe('2600/2600')
        ->and($data['totals']['day_type']['text'])->toBe('Hard')
        ->and(array_column($data['meals'], 'planned_text'))->toBe([null, null, null])
        ->and(array_column(array_column($data['meals'], 'check_in'), 'state'))->toBe(['not_checked_in', 'not_checked_in', 'not_checked_in'])
        ->and($data['off_plan'])->toBe([])
        ->and($data['other_activities'])->toBe([])
        ->and($data['weigh_ins'])->toBe([['id' => 'weigh-in', 'time_text' => '7:00 AM', 'weight_text' => null]])
        ->and($data['steps'])->toBe(['target_text' => 'Step count target 6-8k', 'actual_text' => null])
        ->and(array_column($data['busy_periods'], 'label'))->toBe(['Team meeting'])
        ->and($data['add_meal_time'])->toBe('14:00')
        ->and($data['workouts'][0]['mesocycle_id'])->toBe($mine->id)
        ->and($data['workouts'][0]['title'])->toBe('Week 2 · Day 2 · Lower A')
        ->and($data['workouts'][0]['time_text'])->toBe('5:30 PM')
        ->and($data['workouts'][0]['state'])->toBe('planned')
        ->and($data['banners'])->toBe([])
        ->and(DayTarget::query()->where('user_id', $other->id)->count())->toBe(80);
});

it('MESO-060 MESO-064 offers the current mesocycle\'s active rest on any day shown', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    $user = TodaySeed::user();
    $mesocycle = TodaySeed::mesocycle($user);
    TodaySeed::targets($user, last: '2026-10-20');
    TrainSeed::trained($mesocycle, 2, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-28', flat: true);

    $later = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-12'])->assertOk()->json('data');

    expect($later['date'])->toBe('2026-10-12')
        ->and($later['next_mesocycle']['active_rest']['start_date'])->toBe('2026-10-17');
});

it('SCH-020 SCH-023 after the plan\'s last target, today opens the same whether or not its date is given', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::targets($user);

    $implicit = $this->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');
    $explicit = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-15'])->assertOk()->json('data');

    expect($explicit['date'])->toBe('2026-10-15')
        ->and($explicit)->toBe($implicit);
});

it('SCH-020 a day of a week the plan can show opens as given, even past the last target or before the start', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::targets($user);

    $friday = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-16'])->assertOk()->json('data');

    Carbon::setTestNow('2026-09-10 10:00:00');
    $tuesday = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-09-08'])->assertOk()->json('data');

    expect($friday['date'])->toBe('2026-10-16')
        ->and($tuesday['date'])->toBe('2026-09-08');
});

it('SCH-003 SCH-040 a past mesocycle\'s week shows its logged session once another mesocycle is current', function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    $user = TodaySeed::user();
    $first = TodaySeed::mesocycle($user);
    TodaySeed::mesocycle($user)->update(['name' => 'Next block', 'start_date' => '2026-10-10']);
    TodaySeed::targets($user, last: '2026-11-06');
    TrainSeed::trained($first, 2, 1, '2026-09-21');

    $day = $this->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-09-21'])->assertOk()->json('data');
    $week = $this->actingAs($user)->postJson('/capabilities/week_show', ['date' => '2026-09-21'])->assertOk()->json('data');

    expect($day['workouts'][0]['mesocycle_id'])->toBe($first->id)
        ->and($day['workouts'][0]['title'])->toBe('Week 2 · Day 1 · Upper A')
        ->and($day['workouts'][0]['state'])->toBe('done')
        ->and($day['workouts'][0]['completed']['text'])->toBe('2 of 4 sets completed')
        ->and($week['days'][2]['date'])->toBe('2026-09-21')
        ->and($week['days'][2]['has_training'])->toBeTrue();
});
