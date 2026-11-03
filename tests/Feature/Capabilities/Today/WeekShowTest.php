<?php

use App\Models\DayTarget;
use App\Models\MealCheckIn;
use App\Models\OtherActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

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
        ->and([$data['previous_week'], $data['next_week']])->toBe(['2026-09-16', '2026-09-30']);
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

it('SCH-023 keeps a date outside the plan inside it', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::targets($user);

    $data = $this->actingAs($user)->postJson('/capabilities/week_show', ['date' => '2027-06-01'])->assertOk()->json('data');

    expect($data['week_start'])->toBe('2026-10-03')
        ->and(array_column(array_filter($data['days'], fn ($day) => $day['is_selected']), 'date'))->toBe(['2026-10-09']);
});

it('CAP-020 shows only the signed-in user\'s rows', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    TodaySeed::stranger();
    $user = TodaySeed::user();
    TodaySeed::targets($user);

    $data = $this->actingAs($user)->postJson('/capabilities/week_show')->assertOk()->json('data');

    assertMatchesScreenFixture('week_show', $data);
    expect($data['week_start'])->toBe('2026-09-19')
        ->and($data['heading'])->toBe('WEEK 2')
        ->and($data['pills'])->toBe([])
        ->and(array_unique(array_column($data['days'], 'calories_text')))->toBe(['2600'])
        ->and(array_filter(array_column($data['days'], 'has_training')))->toBe([])
        ->and(array_filter(array_column($data['days'], 'is_complete')))->toBe([])
        ->and($data['calendar'])->toBe(['first' => '2026-09-12', 'last' => '2026-10-09']);
});

it('SCH-020 SCH-021 the week strip reads only what it shows, and still finds the green dots', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, TodaySeed::mesocycle($user));
    $tuesday = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-22')->firstOrFail();
    foreach ($tuesday->meals as $meal) {
        MealCheckIn::factory()->create([
            'meal_id' => $meal['id'], 'kcal' => $meal['kcal'], 'protein_g' => $meal['protein_g'], 'fat_g' => $meal['fat_g'],
            'carbs_g' => $meal['carbs_g'], 'is_manual' => false,
        ]);
    }

    // A test before it in the same warm worker can leave its queries in the log.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = $this->actingAs($user)->postJson('/capabilities/week_show')->assertOk()->json('data');
    $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
    // The warm app keeps the connection between tests, so the log is turned off again.
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect(array_column($data['days'], 'is_complete', 'date')['2026-09-22'])->toBeTrue()
        ->and($sql)->toContain('meal_check_ins')
        ->and($sql)->not->toContain('weigh_ins')
        ->and($sql)->not->toContain('weigh_in_schedule_entries')
        ->and($sql)->not->toContain('`steps`')
        ->and($sql)->toContain('other_activities')
        ->and($sql)->not->toContain('planned_foods')
        ->and($sql)->not->toContain('planned_recipes');
});

/** A user in a `$phase` phase from the plan start, with `$hard` hard Other activities and two sessions in week 2. */
function cardioWeek(string $phase, int $hard): User
{
    $user = TodaySeed::user();
    TrainSeed::phase($user, $phase, '2026-09-12');
    $mesocycle = TodaySeed::mesocycle($user);
    TodaySeed::targets($user);
    TrainSeed::trained($mesocycle, 2, 1, '2026-09-21');
    TrainSeed::trained($mesocycle, 2, 2, '2026-09-23');
    foreach (array_slice(['2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22', '2026-09-24'], 0, $hard) as $date) {
        OtherActivity::factory()->for($user)->create(['date' => $date, 'name' => 'Intervals', 'intensity' => 'hard']);
    }
    // Light, outside the week, or deleted: none of these count.
    OtherActivity::factory()->for($user)->create(['date' => '2026-09-25', 'name' => 'Walk', 'intensity' => 'light']);
    OtherActivity::factory()->for($user)->create(['date' => '2026-09-18', 'name' => 'Intervals', 'intensity' => 'hard']);
    OtherActivity::factory()->for($user)->create(['date' => '2026-09-26', 'name' => 'Intervals', 'intensity' => 'hard']);
    OtherActivity::factory()->for($user)->create(['date' => '2026-09-25', 'name' => 'Intervals', 'intensity' => 'hard'])->delete();

    return $user;
}

it('SCH-062 warns of too much cardio for more than 4 hard Other activities in a Fat loss week', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');

    $data = $this->actingAs(cardioWeek('fatLoss', 5))->postJson('/capabilities/week_show')->assertOk()->json('data');

    assertMatchesScreenFixture('week_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('week_show.warning'), $data))->toBe([])
        ->and($data['warnings'])->toBe([[
            'text' => 'Too much cardio hurts lifting recovery. That makes 5 hard sessions this week; more than 4 is a lot during fat loss.',
            'tone' => 'warning',
        ]]);
});

it('SCH-062 does not warn for 4 hard Other activities, nor outside Fat loss', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');

    $four = $this->actingAs(cardioWeek('fatLoss', 4))->postJson('/capabilities/week_show')->assertOk()->json('data');
    $gain = $this->actingAs(cardioWeek('muscleGain', 5))->postJson('/capabilities/week_show')->assertOk()->json('data');

    expect($four['warnings'])->toBe([])
        ->and($gain['warnings'])->toBe([]);
});

it('PLAN-008 SCH-020 the week strip starts on the new review day from week 4', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TodaySeed::targets($user);
    // Weeks start Saturday 09-12; from 09-26 they start Thursday, so week 2 runs 5 days and week 4 starts 10-08.
    $user->plan->reviewDayChanges()->create(['effective_on' => '2026-09-26', 'review_weekday' => 4]);

    $data = $this->actingAs($user)->postJson('/capabilities/week_show', ['date' => '2026-10-10'])->assertOk()->json('data');

    expect($data['week_start'])->toBe('2026-10-08')
        ->and($data['days'][0]['date'])->toBe('2026-10-08');
});
