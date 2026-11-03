<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Support\ReadKey;
use App\Domain\Safety\ScreeningItem;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Exercise;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// SET-012: the Nutrition tab, the exercise screen, the eligibility re-check and the read keys of nutrition, exercise,
// day-change workout and eligibility writes count days in the user's time zone. 21:58 UTC on Friday 9 Oct is 07:58 on
// Saturday 10 Oct in Brisbane.

afterEach(fn () => Carbon::setTestNow());

function zoned(User $user, string $zone): User
{
    $user->forceFill(['time_zone' => $zone])->save();

    return $user;
}

it('SET-012 a meal on the user\'s local today drops the undated Nutrition tab', function (string $zone, string $date, bool $undated) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = TodaySeed::user();
    TodaySeed::mesocycle($user);
    TodaySeed::targets($user, last: '2026-10-16');
    zoned($user, $zone);
    $meal = DayTargetMeal::query()->where('user_id', $user->id)->whereHas('dayTarget', fn ($query) => $query->whereDate('date', $date))
        ->orderBy('id')->firstOrFail();

    $keys = MealReads::keys($meal->fresh());

    expect(in_array(ReadKey::for('nutrition_show'), $keys, true))->toBe($undated);
})->with([
    'Brisbane, local today' => ['Australia/Brisbane', '2026-10-10', true],
    'UTC, local tomorrow' => ['UTC', '2026-10-10', false],
    'UTC, local today' => ['UTC', '2026-10-09', true],
    'Brisbane, local yesterday' => ['Australia/Brisbane', '2026-10-09', false],
]);

it('SET-012 a renamed exercise drops Today on the user\'s local day', function (string $zone, string $today, ?string $notToday) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zoned(TrainSeed::user(), $zone);
    $exercise = Exercise::factory()->custom($user)->create(['name' => 'Press', 'muscle_group' => 'chest']);

    $invalidates = test()->actingAs($user)->postJson('/capabilities/custom_exercise_save', [
        'exercise_id' => (string) $exercise->id, 'name' => 'Flat Press', 'muscle_group' => 'chest', 'equipment' => 'barbell', 'load_type' => 'barbell',
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data.invalidates');

    expect($invalidates)->toContain(ReadKey::for('today_show', ['date' => $today]), ReadKey::for('today_show'));
    if ($notToday !== null) {
        expect($invalidates)->not->toContain(ReadKey::for('today_show', ['date' => $notToday]));
    }
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10', null],
    'UTC' => ['UTC', '2026-10-09', '2026-10-10'],
]);

it('SET-012 exercise_show gives the rep range of the mesocycle current on the user\'s local day', function (string $zone, ?string $repRange) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zoned(TrainSeed::user(), $zone);
    $exercise = Exercise::factory()->create(['rep_range_min' => 10, 'rep_range_max' => 20]);
    // The earlier mesocycle runs Monday 7 Sep for five weeks and does not hold the exercise; the new one starts Saturday.
    TrainSeed::mesocycle($user);
    TrainSeed::mesocycle($user, ['start_date' => '2026-10-10', 'days' => [['id' => '01JB6Q400A2B3C4D5E6F7G8H9J', 'label' => 'Day', 'weekday' => 6, 'slots' => [
        ['id' => '01JB6Q401A2B3C4D5E6F7G8H9J', 'exercise_id' => $exercise->id, 'muscle_group' => 'chest', 'sets' => 3],
    ]]]]);

    $data = test()->actingAs($user)->postJson('/capabilities/exercise_show', ['exercise_id' => (string) $exercise->id])->assertOk()->json('data');

    expect($data['rep_range_text'])->toBe($repRange);
})->with([
    'Brisbane' => ['Australia/Brisbane', '10–20 reps'],
    'UTC' => ['UTC', null],
]);

it('SET-012 a day change names Today from the user\'s local day on', function (string $zone, bool $named) {
    // 22:00 on Friday 9 Oct in New York is Saturday in UTC. Plan weeks run Monday to Sunday, so 5–11 Oct holds both
    // days. Monday's session of week 5 (5 Oct) is relabelled; its day runs on Mondays only, never on Friday.
    Carbon::setTestNow('2026-10-10 02:00:00');
    $user = zoned(TrainSeed::user(), $zone);
    $mesocycle = TrainSeed::mesocycle($user);

    $invalidates = test()->actingAs($user)->postJson('/capabilities/workout_day_relabel', [
        'workout_id' => WorkoutIds::workout((string) $mesocycle->id, 5, 1), 'label' => 'Upper C',
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data.invalidates');

    expect(in_array(ReadKey::for('today_show', ['date' => '2026-10-09']), $invalidates, true))->toBe($named);
})->with([
    'New York' => ['America/New_York', true],
    'UTC' => ['UTC', false],
]);

it('SET-012 the eligibility re-check screen measures age on the user\'s local day', function (string $zone, bool $applies) {
    // Born 10 Oct 2008: 18 on Saturday in Brisbane, still 17 on Friday in UTC.
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zoned(User::factory()->create(), $zone);
    Profile::factory()->for($user)->create(['birth_date' => '2008-10-10']);

    $questions = test()->actingAs($user)->postJson('/capabilities/eligibility_recheck_show')->assertOk()->json('data.questions');
    $age = collect($questions)->firstWhere('item', 'ageOutOfRange');

    expect($age['applies'])->toBe($applies);
})->with([
    'Brisbane' => ['Australia/Brisbane', false],
    'UTC' => ['UTC', true],
]);

it('SET-012 a switch to training only drops Today on the user\'s local day', function (string $zone, string $today) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zoned(User::factory()->create(), $zone);
    Profile::factory()->for($user)->create();
    Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    Phase::factory()->for($user)->create(['type' => 'fatLoss', 'start_date' => '2026-09-07', 'end_date' => '2026-12-07']);
    DayTarget::factory()->for($user)->create(['date' => '2026-10-12']);

    $data = test()->actingAs($user)->postJson('/capabilities/eligibility_recheck', ['answers' => array_map(
        fn (ScreeningItem $item) => ['item' => $item->value, 'applies' => $item->value === 'diabetes'],
        ScreeningItem::cases())], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data');

    expect($data['outcome'])->toBe('switchToTrainingOnly')
        ->and($data['invalidates'])->toContain(ReadKey::for('today_show', ['date' => $today]));
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10'],
    'UTC' => ['UTC', '2026-10-09'],
]);
