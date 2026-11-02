<?php

use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;

// The HTTP seam of day_redistribute_show and meal_redistribute. The rules are RedistributionTest's and
// RedistributeMealsTest's.

beforeEach(fn () => Carbon::setTestNow('2026-09-23 06:00:00'));
afterEach(fn () => Carbon::setTestNow());

function redistributeOver(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('DAY-002 refuses day_redistribute_show and meal_redistribute when signed out', function (string $name, array $input) {
    redistributeOver(null, $name, $input)->assertUnauthorized();
})->with([
    'day_redistribute_show' => ['day_redistribute_show', ['date' => '2026-09-23']],
    'meal_redistribute' => ['meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [TodaySeed::BREAKFAST]]],
]);

it('DAY-002 answers 404 for another user\'s meal', function (string $name, string $field) {
    TodaySeed::day(User::factory()->create());

    redistributeOver(User::factory()->create(), $name, ['date' => '2026-09-23', $field => [TodaySeed::BREAKFAST]])->assertNotFound();
})->with([
    'day_redistribute_show' => ['day_redistribute_show', 'excluded_meal_ids'],
    'meal_redistribute' => ['meal_redistribute', 'meal_ids'],
]);

it('DAY-002 answers 422 naming a meal id that is neither a ULID nor a UUID', function () {
    redistributeOver(User::factory()->create(), 'meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => ['abc']])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'meal_ids.0');
});

it('DAY-002 DAY-021 SAFE-018 day_redistribute_show matches its screen fixture', function () {
    $user = User::factory()->create();
    TodaySeed::day($user);

    $response = redistributeOver($user, 'day_redistribute_show', ['date' => '2026-09-23', 'excluded_meal_ids' => [TodaySeed::LUNCH]])->assertOk();

    assertMatchesScreenFixture('day_redistribute_show', $response->json('data'));
    expect($response->json('data.rows.*.selected'))->toBe([true, false, true])
        ->and($response->json('data.rows.*.name'))->toBe(['Breakfast', 'Lunch', 'Dinner'])
        ->and($response->json('data.rows.1.kcal_text'))->toBe('780 kcal')
        ->and($response->json('data.can_confirm'))->toBeTrue();
});

it('DAY-002 CAP-013 meal_redistribute saves, says what changed, and its undo puts the day back', function () {
    $user = User::factory()->create();
    $day = TodaySeed::day($user);
    TodaySeed::checkIn(TodaySeed::LUNCH);
    $before = $day->fresh()->meals;

    $response = redistributeOver($user, 'meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [TodaySeed::BREAKFAST, TodaySeed::DINNER]])
        ->assertOk()
        ->assertJsonPath('data.toast', '2 meals re-targeted')
        ->assertJsonPath('data.invalidates', [
            'today_show{"date":"2026-09-23"}', 'today_show{}', 'day_redistribute_show{"date":"2026-09-23"}', 'day_adjust_show{"date":"2026-09-23"}',
        ]);
    expect($day->fresh()->meals)->not->toBe($before);

    redistributeOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(DayTarget::query()->find($day->id)->meals)->toEqual($before);
});

it('DAY-002 answers 422 in plain words for a meal that is checked in', function () {
    $user = User::factory()->create();
    TodaySeed::day($user);
    TodaySeed::checkIn(TodaySeed::LUNCH);

    redistributeOver($user, 'meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [TodaySeed::LUNCH]])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Lunch can\'t take a share: it is checked in, past, or its foods are locked.');
});
