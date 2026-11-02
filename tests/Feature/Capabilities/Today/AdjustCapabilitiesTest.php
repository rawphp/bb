<?php

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;

// The HTTP seam of day_adjust_show and day_adjust. The rules are AdjustmentTest's. Plan weeks start on Monday, so
// Wednesday 23 September shares its week with Saturday 26th; Tuesday 22nd is past.

beforeEach(fn () => Carbon::setTestNow('2026-09-23 06:00:00'));
afterEach(fn () => Carbon::setTestNow());

function adjustOver(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function adjustUser(): User
{
    $user = User::factory()->create();
    Plan::factory()->for($user)->create(['start_date' => '2026-08-31', 'review_weekday' => 1]);
    foreach (['2026-09-22', '2026-09-23', '2026-09-26'] as $date) {
        TodaySeed::day($user, $date);
    }

    return $user;
}

it('DAY-020 refuses day_adjust_show and day_adjust when signed out', function (string $name) {
    adjustOver(null, $name, ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 100, 'other_date' => '2026-09-26'])->assertUnauthorized();
})->with(['day_adjust_show', 'day_adjust']);

it('DAY-020 answers 404 for another user\'s meal', function (string $name) {
    adjustUser();

    adjustOver(User::factory()->create(), $name, ['date' => '2026-09-23', 'mode' => 'meals', 'kcal' => 100, 'from_meal_id' => TodaySeed::LUNCH, 'to_meal_id' => TodaySeed::DINNER])
        ->assertNotFound();
})->with(['day_adjust_show', 'day_adjust']);

it('DAY-020 another user\'s day is not this user\'s: it has no targets to adjust', function () {
    adjustUser();

    adjustOver(User::factory()->create(), 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 100, 'other_date' => '2026-09-26'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This day has no targets to adjust yet.');
});

it('DAY-020 answers 422 naming other_date when a move between days has none', function () {
    adjustOver(adjustUser(), 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'other_date');
});

it('DAY-020 DAY-021 NUT-021 day_adjust_show matches its screen fixture', function () {
    $response = adjustOver(adjustUser(), 'day_adjust_show', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-26'])
        ->assertOk();

    assertMatchesScreenFixture('day_adjust_show', $response->json('data'));
    expect($response->json('data.day_options'))->toBe([
        ['date' => '2026-09-22', 'label' => 'Tue, Sep 22', 'enabled' => false],
        ['date' => '2026-09-26', 'label' => 'Sat, Sep 26', 'enabled' => true],
    ])
        ->and($response->json('data.day.kcal_text'))->toBe('2,400 kcal')
        ->and($response->json('data.other_day.kcal_text'))->toBe('2,800 kcal')
        ->and($response->json('data.can_save'))->toBeTrue();
});

it('NUT-024 day_adjust_show says why a move to a finished day is refused', function () {
    adjustOver(adjustUser(), 'day_adjust_show', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-22'])
        ->assertOk()
        ->assertJsonPath('data.refusal', 'Tuesday is finished, so its calories can\'t move.')
        ->assertJsonPath('data.can_save', false);
});

it('NUT-021 day_adjust saves both days and says which reads changed', function () {
    $user = adjustUser();

    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-26'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'today_show{"date":"2026-09-23"}', 'today_show{}', 'day_adjust_show{"date":"2026-09-23"}', 'day_redistribute_show{"date":"2026-09-23"}',
            'today_show{"date":"2026-09-26"}', 'day_adjust_show{"date":"2026-09-26"}', 'day_redistribute_show{"date":"2026-09-26"}',
        ]);

    $kcal = $user->hasMany(\App\Models\DayTarget::class)->orderBy('date')->pluck('kcal')->all();
    expect($kcal)->toBe([2600.0, 2400.0, 2800.0]);
});

it('NUT-024 day_adjust refuses a move to a finished day in plain words and saves nothing', function () {
    $user = adjustUser();

    adjustOver($user, 'day_adjust', ['date' => '2026-09-23', 'mode' => 'days', 'kcal' => 200, 'other_date' => '2026-09-22'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Tuesday is finished, so its calories can\'t move.');
});
