<?php

use App\Models\DayTarget;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// The HTTP seam of missed_session_resolve. The rules are ResolveMissedSessionTest's. Monday 21 September's Upper A
// (week 3, day 1) was missed; Tuesday 22nd is a rest day.

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function missedOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/missed_session_resolve', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SCH-057 refuses missed_session_resolve when signed out', function () {
    missedOver(null, ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5', 'week' => 3, 'day' => 1, 'choice' => 'skip'])->assertUnauthorized();
});

it('SCH-057 answers 404 for another user\'s mesocycle', function () {
    $theirs = TrainSeed::mesocycle(TrainSeed::user());

    missedOver(TrainSeed::user(), ['mesocycle_id' => $theirs->id, 'week' => 3, 'day' => 1, 'choice' => 'skip'])->assertNotFound();
});

it('SCH-057 answers 422 naming date when a move has none', function () {
    $user = TrainSeed::user();

    missedOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'week' => 3, 'day' => 1, 'choice' => 'do_it_today'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'date');
});

it('SCH-056 CAP-013 a move shows the re-target toast, and its undo puts the day and the session back', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $day = TodaySeed::day($user, '2026-09-22', ['day_type' => 'rest']);
    $before = $day->fresh()->only(['day_type', 'kcal', 'carbs_g', 'meals']);

    $response = missedOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1, 'choice' => 'do_it_today', 'date' => '2026-09-22'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Meals updated for your 5:30 PM workout');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-22"}', 'today_show{"date":"2026-09-21"}', 'train_show{}', 'week_show{"date":"2026-09-27"}', 'week_show{}')
        ->and(WorkoutSession::query()->count())->toBe(1);

    $this->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $response->json('data.undo')], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(DayTarget::query()->find($day->id)->only(['day_type', 'kcal', 'carbs_g', 'meals']))->toEqual($before)
        ->and(WorkoutSession::query()->count())->toBe(0);
});
