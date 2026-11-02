<?php

use App\Models\DayTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The HTTP seam of day_optimize_show and day_optimize. The rules are OptimizeDayTest's. Thursday 24 September 2026
// holds Lower A at 5:30 PM; today is Tuesday 22nd.

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function optimizeOver(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SCH-036 refuses day_optimize_show and day_optimize when signed out', function (string $name) {
    optimizeOver(null, $name, ['date' => '2026-09-24'])->assertUnauthorized();
})->with(['day_optimize_show', 'day_optimize']);

it('SCH-036 answers 422 for a date that is not a date', function (string $name) {
    optimizeOver(TrainSeed::user(), $name, ['date' => '24/09/2026'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'date');
})->with(['day_optimize_show', 'day_optimize']);

it('SCH-036 CAP-020 another user\'s day is not this user\'s: it has no meals to optimize, and theirs is left alone', function () {
    $them = TrainSeed::user();
    TrainSeed::mesocycle($them);
    $theirs = SheetSeed::day($them, '2026-09-24');
    $user = TrainSeed::user();

    optimizeOver($user, 'day_optimize_show', ['date' => '2026-09-24'])
        ->assertOk()
        ->assertJsonPath('data.rows', [])
        ->assertJsonPath('data.can_save', false);
    optimizeOver($user, 'day_optimize', ['date' => '2026-09-24'])->assertStatus(422);

    expect($theirs->fresh()->meals)->toEqual($theirs->meals);
});

it('SCH-036 shows a seeded day\'s preview with the clock fixed', function () {
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-09-24');

    $data = optimizeOver($user, 'day_optimize_show', ['date' => '2026-09-24'])->assertOk()->json('data');

    assertMatchesScreenFixture('day_optimize_show', $data);
    expect($data['can_save'])->toBeTrue()
        ->and(collect($data['rows'])->contains('changed', true))->toBeTrue();
});

it('SCH-036 SCH-056 CAP-013 saves what the preview showed with a toast, then shows nothing left to change, and undo puts the day back', function () {
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);
    $day = SheetSeed::day($user, '2026-09-24');
    $before = $day->fresh()->only(['kcal', 'meals']);

    $response = optimizeOver($user, 'day_optimize', ['date' => '2026-09-24'])->assertOk()->assertJsonPath('data.toast', 'Meals optimized');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-24"}', 'day_optimize_show{"date":"2026-09-24"}', 'week_show{"date":"2026-09-24"}');

    $empty = optimizeOver($user, 'day_optimize_show', ['date' => '2026-09-24'])->assertOk()->json('data');
    assertMatchesScreenFixture('day_optimize_show.empty', $empty);
    expect($empty['message'])->toBe('Your meals are already in the best places.')
        ->and($empty['can_save'])->toBeFalse();

    optimizeOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(DayTarget::query()->find($day->id)->only(['kcal', 'meals']))->toEqual($before);
});
