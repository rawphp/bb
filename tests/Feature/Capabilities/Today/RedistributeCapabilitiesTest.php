<?php

use App\Models\DayTarget;
use App\Models\MealPlanningState;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;

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
    'meal_redistribute' => ['meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [SheetSeed::BREAKFAST]]],
]);

it('DAY-002 answers 404 for another user\'s meal', function (string $name, string $field) {
    SheetSeed::day(User::factory()->create());

    redistributeOver(User::factory()->create(), $name, ['date' => '2026-09-23', $field => [SheetSeed::BREAKFAST]])->assertNotFound();
})->with([
    'day_redistribute_show' => ['day_redistribute_show', 'excluded_meal_ids'],
    'meal_redistribute' => ['meal_redistribute', 'meal_ids'],
]);

it('DAY-002 answers 422 naming the bad field', function (string $name, array $input, string $field) {
    redistributeOver(User::factory()->create(), $name, $input)
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with([
    'meal_redistribute with a meal id that is neither a ULID nor a UUID' => ['meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => ['abc']], 'meal_ids.0'],
    'day_redistribute_show with a date that is not one' => ['day_redistribute_show', ['date' => 'Wednesday'], 'date'],
]);

it('DAY-002 DAY-021 SAFE-018 day_redistribute_show matches its screen fixture', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);

    $response = redistributeOver($user, 'day_redistribute_show', ['date' => '2026-09-23', 'excluded_meal_ids' => [SheetSeed::LUNCH]])->assertOk();

    assertMatchesScreenFixture('day_redistribute_show', $response->json('data'));
    expect($response->json('data.rows.*.selected'))->toBe([true, false, true])
        ->and($response->json('data.rows.*.name'))->toBe(['Breakfast', 'Lunch', 'Dinner'])
        ->and($response->json('data.rows.1.kcal_text'))->toBe('780 kcal')
        ->and($response->json('data.note'))->toBe('Small misses are fine. Within 10% on calories and protein each day is a success.')
        ->and($response->json('data.can_confirm'))->toBeTrue();
});

it('DAY-002 a meal left out in capitals is left out', function () {
    $user = User::factory()->create();
    SheetSeed::day($user, '2026-09-23', ['meals' => [
        ['id' => strtolower(SheetSeed::CLIENT_MEAL), 'name' => 'Dinner', 'time' => '19:00', 'kcal' => 900, 'protein_g' => 60, 'fat_g' => 25, 'carbs_g' => 100],
    ]]);

    redistributeOver($user, 'day_redistribute_show', ['date' => '2026-09-23', 'excluded_meal_ids' => [SheetSeed::CLIENT_MEAL]])
        ->assertOk()
        ->assertJsonPath('data.rows.0.selected', false)
        ->assertJsonPath('data.can_confirm', false);
});

it('DAY-002 day_redistribute_show lists nothing on a day with no targets', function () {
    redistributeOver(User::factory()->create(), 'day_redistribute_show', ['date' => '2026-09-23'])
        ->assertOk()
        ->assertJsonPath('data.rows', [])
        ->assertJsonPath('data.can_confirm', false);
});

it('DAY-002 one meal re-targeted says which, and a meal id in capitals finds its meal', function () {
    $user = User::factory()->create();
    SheetSeed::day($user, '2026-09-23', ['meals' => [
        ['id' => strtolower(SheetSeed::CLIENT_MEAL), 'name' => 'Dinner', 'time' => '19:00', 'kcal' => 900, 'protein_g' => 60, 'fat_g' => 25, 'carbs_g' => 100],
    ]]);

    redistributeOver($user, 'meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [SheetSeed::CLIENT_MEAL]])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Dinner re-targeted');
});

it('DAY-002 CAP-013 meal_redistribute saves, says what changed, and its undo puts the day back', function () {
    $user = User::factory()->create();
    $day = SheetSeed::day($user);
    SheetSeed::checkIn(SheetSeed::LUNCH);
    $before = $day->fresh()->meals;

    $response = redistributeOver($user, 'meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [SheetSeed::BREAKFAST, SheetSeed::DINNER]])
        ->assertOk()
        ->assertJsonPath('data.toast', '2 meals re-targeted')
        ->assertJsonPath('data.invalidates', [
            'today_show{"date":"2026-09-23"}', 'today_show{}', 'day_redistribute_show{"date":"2026-09-23"}', 'day_adjust_show{"date":"2026-09-23"}',
        ]);
    expect($day->fresh()->meals)->not->toBe($before);

    redistributeOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(DayTarget::query()->find($day->id)->meals)->toEqual($before);
});

it('DAY-003 DAY-004 CAP-013 undo puts back the planning states meal_redistribute set, a new one and a changed one', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);
    SheetSeed::food(SheetSeed::BREAKFAST);
    SheetSeed::food(SheetSeed::DINNER);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::DINNER, 'state' => 'done_planning']);

    $response = redistributeOver($user, 'meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [SheetSeed::BREAKFAST, SheetSeed::DINNER]])->assertOk();
    expect(MealPlanningState::query()->pluck('state', 'meal_id')->all())
        ->toEqual([SheetSeed::BREAKFAST => 'still_planning', SheetSeed::DINNER => 'still_planning']);

    redistributeOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(MealPlanningState::query()->pluck('state', 'meal_id')->all())->toBe([SheetSeed::DINNER => 'done_planning']);
});

it('DAY-002 answers 422 in plain words for a meal that is checked in', function () {
    $user = User::factory()->create();
    SheetSeed::day($user);
    SheetSeed::checkIn(SheetSeed::LUNCH);

    redistributeOver($user, 'meal_redistribute', ['date' => '2026-09-23', 'meal_ids' => [SheetSeed::LUNCH]])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Lunch can\'t take a share: it is checked in, past, or its foods are locked.');
});
