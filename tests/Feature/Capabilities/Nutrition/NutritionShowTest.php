<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;

// The HTTP seam of nutrition_show and its screen fixtures. The rules are unit tests (NutritionPayloadTest).

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('NAV-001 refuses nutrition_show when signed out', function () {
    $this->postJson('/capabilities/nutrition_show')->assertUnauthorized();
});

it('NAV-001 answers 422 for a date that is not a date', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/nutrition_show', ['date' => 'next tuesday'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'date');
});

it('NAV-001 MEAL-047 shows the seeded day\'s meals with the clock fixed, and only the user\'s own', function () {
    TodaySeed::stranger();
    $user = TodaySeed::user();
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, TodaySeed::mesocycle($user));

    $data = $this->actingAs($user)->postJson('/capabilities/nutrition_show')->assertOk()->json('data');

    assertMatchesScreenFixture('nutrition_show', $data);
    expect($data['date_text'])->toBe('Today')
        ->and(array_column($data['meals'], 'name'))->toBe(['Breakfast', 'Lunch', 'Dinner'])
        // Breakfast has 80 g oats, lunch nothing planned, dinner 2 servings of chili (250 kcal each).
        ->and(array_column($data['meals'], 'kcal_text'))->toBe(['304 / 780 kcal', '0 / 780 kcal', '500 / 1040 kcal'])
        ->and($data['meals'][1]['check_in']['state'])->toBe('onTrack')
        ->and($data['empty_text'])->toBeNull();
});

it('NAV-001 opens the day the date names', function () {
    $user = TodaySeed::user();
    TodaySeed::targets($user);

    $data = $this->actingAs($user)->postJson('/capabilities/nutrition_show', ['date' => '2026-09-24'])->assertOk()->json('data');

    assertMatchesScreenFixture('nutrition_show', $data);
    expect($data['date_text'])->toBe('Thursday, Sep 24')
        ->and(count($data['meals']))->toBe(3);
});

it('NAV-001 shows a user without a plan the empty state', function () {
    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/nutrition_show')->assertOk()->json('data');

    assertMatchesScreenFixture('nutrition_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('nutrition_show.empty'), $data))->toBe([])
        ->and($data['meals'])->toBe([])
        ->and($data['empty_text'])->toBe('No meals planned today.');
});
