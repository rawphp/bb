<?php

use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The HTTP seam of meal_add. The rules are AddMealTest's. Today is Tuesday 22 September 2026.

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function mealAdd(?User $user, array $values = []): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/meal_add', array_replace(['id' => (string) Str::ulid(), 'date' => '2026-09-23', 'time' => '15:00'], $values), ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SCH-031 refuses meal_add when signed out', function () {
    mealAdd(null)->assertUnauthorized();
});

it('SCH-031 CAP-019 answers 404 for an id another user\'s meal holds, and changes neither day', function () {
    $them = TrainSeed::user();
    $theirs = SheetSeed::day($them);
    $user = TrainSeed::user();
    $mine = SheetSeed::day($user, '2026-09-24');

    mealAdd($user, ['id' => SheetSeed::BREAKFAST, 'date' => '2026-09-24'])->assertNotFound();

    expect($theirs->fresh()->meals)->toEqual($theirs->meals)
        ->and($mine->fresh()->meals)->toEqual($mine->meals);
});

it('SCH-031 answers 422 naming the bad field, and for an id already on the user\'s plan', function (array $values, ?string $field) {
    $user = TrainSeed::user();
    SheetSeed::day($user);

    $response = mealAdd($user, $values)->assertStatus(422);

    if ($field !== null) {
        $response->assertJsonPath('error.violations.0.field', $field);
    }
})->with([
    'a time of 3 PM' => [['time' => '3 PM'], 'time'],
    'the id of a meal of the day' => [['id' => SheetSeed::LUNCH], null],
]);

it('SCH-031 SCH-056 CAP-013 adds the meal with a toast, and undo takes it back off the day', function () {
    $user = TrainSeed::user();
    $day = SheetSeed::day($user);
    $before = $day->fresh()->only(['kcal', 'meals']);

    $response = mealAdd($user)->assertOk()->assertJsonPath('data.toast', 'Meal added at 3:00 PM');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-23"}', 'day_redistribute_show{"date":"2026-09-23"}', 'week_show{"date":"2026-09-23"}')
        ->and(DayTarget::query()->find($day->id)->meals)->toHaveCount(4);

    test()->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $response->json('data.undo')], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    expect(DayTarget::query()->find($day->id)->only(['kcal', 'meals']))->toEqual($before);
});
