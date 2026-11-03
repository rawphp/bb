<?php

use App\Actions\Today\PlannedSession;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Capabilities\Workout\WorkoutDayReads;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\MealCheckIn;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Support\Today\TodayWeight;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// SET-012: Today, the week strip and Today's weigh-ins count days in the user's time zone.

afterEach(fn () => Carbon::setTestNow());

function zonedTodayUser(string $zone): User
{
    $user = TodaySeed::user();
    TodaySeed::mesocycle($user);
    TodaySeed::targets($user, last: '2026-10-16');
    $user->forceFill(['time_zone' => $zone])->save();

    return $user;
}

it('SET-012 opens Today and the week strip on the user\'s local day', function (string $zone, string $today) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser($zone);

    $data = test()->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');
    $week = test()->actingAs($user)->postJson('/capabilities/week_show')->assertOk()->json('data');

    expect($data['date'])->toBe($today)
        ->and(array_column(array_filter($week['days'], fn (array $day) => $day['is_today']), 'date'))->toBe([$today]);
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10'],
    'UTC' => ['UTC', '2026-10-09'],
]);

it('SET-012 shows a weigh-in on the user\'s local day', function (string $zone, string $shownOn, string $notOn) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser($zone);
    // Today shows a weigh-in row on scheduled days only; the seed's are Wednesday and Saturday.
    WeighInScheduleEntry::query()->where('user_id', $user->id)->firstOrFail()->update(['weekdays' => [1, 2, 3, 4, 5, 6, 7]]);
    WeighIn::factory()->for($user)->create(['weight_kg' => 81.3, 'weighed_at' => '2026-10-09 21:58:00']);

    $on = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => $shownOn])->assertOk()->json('data');
    $off = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => $notOn])->assertOk()->json('data');

    expect(array_column($on['weigh_ins'], 'weight_text'))->toContain('81.3 kg')
        ->and(array_column($off['weigh_ins'], 'weight_text'))->not->toContain('81.3 kg');
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10', '2026-10-09'],
    'UTC' => ['UTC', '2026-10-09', '2026-10-10'],
]);

it('SET-012 a write to the user\'s local today drops the undated Today and week reads', function () {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser('Australia/Brisbane');

    $invalidates = test()->actingAs($user)->postJson('/capabilities/off_plan_log', [
        'id' => (string) Str::ulid(), 'date' => '2026-10-10', 'time' => '07:30',
        'kcal' => 350, 'protein_g' => 4, 'fat_g' => 18, 'carbs_g' => 45, 'name' => 'Birthday cake',
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data.invalidates');

    expect($invalidates)->toContain(ReadKey::for('today_show', ['date' => '2026-10-10']), ReadKey::for('today_show'))
        ->and(TodayReads::weekKeys(['2026-10-10']))->toContain(ReadKey::for('week_show'))
        ->and(TodayReads::keys(['2026-10-08']))->not->toContain(ReadKey::for('today_show'))
        ->and(TodayReads::weekKeys(['2026-10-08']))->not->toContain(ReadKey::for('week_show'));
});

it('SET-012 with no one signed in, today for the undated reads is the UTC day', function () {
    Carbon::setTestNow('2026-10-09 21:58:00');

    expect(TodayReads::keys(['2026-10-09']))->toContain(ReadKey::for('today_show'))
        ->and(TodayReads::keys(['2026-10-10']))->not->toContain(ReadKey::for('today_show'));
});

it('SET-012 buckets the current weight\'s trend by the user\'s local days, even after the zone changes', function () {
    $user = User::factory()->create();
    // 23:00 and 01:00 in Brisbane: one UTC day, two local days.
    WeighIn::factory()->for($user)->create(['weight_kg' => 80.0, 'weighed_at' => '2026-10-08 13:00:00']);
    WeighIn::factory()->for($user)->create(['weight_kg' => 90.0, 'weighed_at' => '2026-10-08 15:00:00']);

    expect(TodayWeight::kg($user->fresh(), null))->toBe(80.0);

    $user->forceFill(['time_zone' => 'Australia/Brisbane'])->save();

    expect(TodayWeight::kg($user->fresh(), null))->toBe(81.0);
});

it('SET-012 Train\'s session for today is the one on the user\'s local day', function (string $zone, ?string $title) {
    // Friday 07:58 in Brisbane is Thursday in UTC; the plan trains Monday, Wednesday and Friday.
    Carbon::setTestNow('2026-10-08 21:58:00');
    $user = zonedTodayUser($zone);

    $data = test()->actingAs($user)->postJson('/capabilities/train_show')->assertOk()->json('data');

    expect($data['todays_session']['title'] ?? null)->toBe($title);
})->with([
    'Brisbane' => ['Australia/Brisbane', 'Week 4 · Day 3 · Upper B'],
    'UTC' => ['UTC', null],
]);

it('SET-012 the Plan screen marks today on the user\'s local day', function (string $zone, float $position) {
    Carbon::setTestNow('2026-10-09 22:30:00');
    $user = zonedTodayUser($zone);

    expect((float) test()->actingAs($user)->postJson('/capabilities/plan_show')->assertOk()->json('data.today_position'))->toBe($position);
})->with([
    'Brisbane' => ['Australia/Brisbane', 4.0],
    'UTC' => ['UTC', 3.86],
]);

it('SET-012 a meal before the user\'s clock is past and cannot take a share', function () {
    // 08:30 on Saturday in Brisbane: breakfast at 08:00 is past.
    Carbon::setTestNow('2026-10-09 22:30:00');
    $brisbane = zonedTodayUser('Australia/Brisbane');
    $utc = zonedTodayUser('UTC');

    $rows = fn (User $user) => array_column(test()->actingAs($user)->postJson('/capabilities/day_redistribute_show', ['date' => '2026-10-10'])
        ->assertOk()->json('data.rows'), 'name');
    $breakfast = fn (User $user) => DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-10-10')->sole()->meals[0]['id'];

    expect($rows($brisbane))->toBe(['Lunch', 'Dinner'])
        ->and($rows($utc))->toBe(['Breakfast', 'Lunch', 'Dinner']);
    test()->actingAs($brisbane)->postJson('/capabilities/meal_redistribute', ['date' => '2026-10-10', 'meal_ids' => [$breakfast($brisbane)]], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422);
    test()->actingAs($utc)->postJson('/capabilities/meal_redistribute', ['date' => '2026-10-10', 'meal_ids' => [$breakfast($utc)]], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();
});

it('SET-012 a workout started from Today stays on the user\'s local day', function () {
    // Friday 07:58 in Brisbane, Thursday 21:58 in UTC.
    Carbon::setTestNow('2026-10-08 21:58:00');
    $user = zonedTodayUser('Australia/Brisbane');
    $before = test()->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');
    expect($before['date'])->toBe('2026-10-09')
        ->and($before['workouts'][0]['state'])->toBe('planned');

    $invalidates = test()->actingAs($user)->postJson('/capabilities/workout_start', ['workout_id' => WorkoutIds::workout($before['workouts'][0]['mesocycle_id'], $before['workouts'][0]['week'], $before['workouts'][0]['day'])], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()->json('data.invalidates');
    $after = test()->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');
    $week = test()->actingAs($user)->postJson('/capabilities/week_show')->assertOk()->json('data');
    $thursday = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-08'])->assertOk()->json('data');

    expect($invalidates)->toContain(ReadKey::for('today_show', ['date' => '2026-10-09']), ReadKey::for('today_show'))
        ->and($after['date'])->toBe('2026-10-09')
        ->and(array_column($after['workouts'], 'state'))->toBe(['started'])
        ->and(collect($week['days'])->firstWhere('date', '2026-10-09')['has_training'])->toBeTrue()
        ->and(collect($week['days'])->firstWhere('date', '2026-10-08')['has_training'])->toBeFalse()
        ->and($thursday['workouts'])->toBe([]);
});

it('SET-012 the Adjust sheet counts a day as finished by the user\'s clock', function () {
    // Thursday 07:58 in Brisbane is Wednesday 21:58 in UTC: Wednesday is over in Brisbane and still today in UTC.
    Carbon::setTestNow('2026-10-07 21:58:00');
    $move = ['date' => '2026-10-08', 'mode' => 'days', 'kcal' => 100, 'other_date' => '2026-10-07'];
    $wednesday = fn (User $user) => collect(test()->actingAs($user)->postJson('/capabilities/day_adjust_show', $move)->assertOk()->json('data.day_options'))
        ->firstWhere('date', '2026-10-07')['enabled'];
    $brisbane = zonedTodayUser('Australia/Brisbane');
    $utc = zonedTodayUser('UTC');

    expect($wednesday($brisbane))->toBeFalse()
        ->and($wednesday($utc))->toBeTrue();
    test()->actingAs($brisbane)->postJson('/capabilities/day_adjust', $move, ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(422);
    test()->actingAs($utc)->postJson('/capabilities/day_adjust', $move, ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
});

it('SET-012 an untimed meal is checked in at the user\'s clock', function (string $zone, string $time) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser($zone);
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-10-10')->sole();
    $day->meals = [['time' => null] + $day->meals[0], ...array_slice($day->meals, 1)];
    $day->save();

    $meals = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-10'])->assertOk()->json('data.meals');

    expect($meals[0]['check_in']['manual']['time'])->toBe($time);
})->with([
    'Brisbane' => ['Australia/Brisbane', '07:58'],
    'UTC' => ['UTC', '21:58'],
]);

it('SET-012 a write to the UTC day still drops the undated reads for a user ahead of UTC', function () {
    Carbon::setTestNow('2026-10-09 21:58:00');
    test()->actingAs(zonedTodayUser('Australia/Brisbane'));

    expect(TodayReads::keys(['2026-10-09']))->toContain(ReadKey::for('today_show'))
        ->and(TodayReads::weekKeys(['2026-10-09']))->toContain(ReadKey::for('week_show'));
});

it('SET-012 starting a session on a later local day refreshes that day\'s Today', function () {
    // Friday's session, started at 07:58 on Saturday in Brisbane: still Friday in UTC.
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser('Australia/Brisbane');
    $friday = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-09'])->assertOk()->json('data.workouts.0');

    $invalidates = test()->actingAs($user)->postJson('/capabilities/workout_start', ['workout_id' => WorkoutIds::workout($friday['mesocycle_id'], $friday['week'], $friday['day'])], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()->json('data.invalidates');

    expect($invalidates)->toContain(ReadKey::for('today_show', ['date' => '2026-10-10']));
});

it('SET-012 skipping a session started this local morning re-targets that day\'s meals', function () {
    Carbon::setTestNow('2026-10-08 21:58:00');
    $user = zonedTodayUser('Australia/Brisbane');
    $friday = test()->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data.workouts.0');
    $id = WorkoutIds::workout($friday['mesocycle_id'], $friday['week'], $friday['day']);
    test()->actingAs($user)->postJson('/capabilities/workout_start', ['workout_id' => $id], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    $skip = test()->actingAs($user)->postJson('/capabilities/workout_skip', ['workout_id' => $id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()->json('data');

    expect($skip['toast'])->toBe('Meals updated for the skipped workout')
        ->and($skip['invalidates'])->toContain(ReadKey::for('day_redistribute_show', ['date' => '2026-10-09']));
});

it('SET-012 finishing a session started this local morning re-targets that day\'s meals', function () {
    // Monday 07:30 to 09:00 in Brisbane is Sunday night in UTC.
    Carbon::setTestNow('2026-09-20 23:00:00');
    $user = TrainSeed::user();
    $user->forceFill(['time_zone' => 'Australia/Brisbane'])->save();
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id]);
    $session = WorkoutSeed::started($mesocycle, 3, 1, '2026-09-20 21:30:00');
    WorkoutSeed::answered($session, 'chest', WorkoutSeed::exerciseId($mesocycle, 1), ['pump' => 'low', 'volume' => 'notEnough']);
    SheetSeed::day($user, '2026-09-21');

    $finish = test()->actingAs($user)->postJson('/capabilities/workout_finish', ['workout_id' => WorkoutSeed::id($session)], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()->json('data');

    expect($finish['toast'])->toContain('Meals updated for your 7:30 AM workout')
        ->and($finish['invalidates'])->toContain(ReadKey::for('today_show', ['date' => '2026-09-21']));
});

it('SET-012 a session started this local morning is refused as started, not as missing, when re-timed', function () {
    // Friday 07:58 in Brisbane is Thursday in UTC: Today must find the session on Friday to know it has started.
    Carbon::setTestNow('2026-10-08 21:58:00');
    $user = zonedTodayUser('Australia/Brisbane');
    $friday = test()->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data.workouts.0');
    test()->actingAs($user)->postJson('/capabilities/workout_start', ['workout_id' => WorkoutIds::workout($friday['mesocycle_id'], $friday['week'], $friday['day'])], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    test()->actingAs($user)->postJson('/capabilities/workout_time_set', ['mesocycle_id' => $friday['mesocycle_id'], 'week' => $friday['week'], 'day' => $friday['day'], 'time' => '18:00'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('error.message', PlannedSession::LOCKED);
});

it('SET-012 a plan-week write refreshes Today from the user\'s local today on, for a user behind UTC', function () {
    // Wednesday 21:00 in New York is Thursday in UTC. Relabelling Friday's session names Today for every day left in
    // the plan week, and Wednesday is one of them.
    Carbon::setTestNow('2026-10-08 01:00:00');
    $user = zonedTodayUser('America/New_York');
    $friday = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-09'])->assertOk()->json('data.workouts.0');

    $invalidates = test()->actingAs($user)->postJson('/capabilities/workout_day_relabel', ['workout_id' => WorkoutIds::workout($friday['mesocycle_id'], $friday['week'], $friday['day']), 'label' => 'Upper C'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()->json('data.invalidates');

    expect($invalidates)->toContain(ReadKey::for('today_show', ['date' => '2026-10-07']));
});

it('SET-012 a meal checked in with no time records the user\'s clock', function (string $zone, string $date, string $time) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser($zone);
    $meal = DayTargetMeal::query()->where('user_id', $user->id)->whereHas('dayTarget', fn ($query) => $query->whereDate('date', $date))
        ->orderBy('id')->firstOrFail();

    test()->actingAs($user)->postJson('/capabilities/meal_check_in', ['meal_id' => $meal->id, 'kcal' => 500, 'protein_g' => 30, 'fat_g' => 15, 'carbs_g' => 60], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(MealCheckIn::query()->where('meal_id', $meal->id)->sole()->time)->toStartWith($time);
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10', '07:58'],
    'UTC' => ['UTC', '2026-10-09', '21:58'],
]);

it('SET-012 a change to the rest of a mesocycle names the Today and week of the user\'s local start day', function () {
    // Friday's session, started at 07:58 on Saturday in Brisbane: Friday in UTC and on the plan, Saturday locally,
    // and Saturday 10 Oct begins the next plan week.
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser('Australia/Brisbane');
    $friday = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => '2026-10-09'])->assertOk()->json('data.workouts.0');
    test()->actingAs($user)->postJson('/capabilities/workout_start', ['workout_id' => WorkoutIds::workout($friday['mesocycle_id'], $friday['week'], $friday['day'])], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();
    $session = WorkoutSession::query()->where('user_id', $user->id)->whereNotNull('actual_start_at')->sole();

    $keys = WorkoutDayReads::keys($user->fresh(), $session, fromWeek: $session->week);

    expect($keys)->toContain(ReadKey::for('today_show', ['date' => '2026-10-10']), ReadKey::for('week_show', ['date' => '2026-10-10']));
});
