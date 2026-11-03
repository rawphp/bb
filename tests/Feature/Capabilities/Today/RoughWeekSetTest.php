<?php

use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;

// The HTTP seam of rough_week_set. The rules are SetRoughWeekTest's.

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function roughSet(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/rough_week_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SCH-063 refuses rough_week_set when signed out', function () {
    roughSet(null, ['week_start' => '2026-09-19', 'rough' => true])->assertUnauthorized();
});

it('SCH-063 answers 422 naming rough when it is missing', function () {
    roughSet(User::factory()->create(), ['week_start' => '2026-09-19'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'rough');
});

it('SCH-063 answers 422 for a week that is not this one, saving nothing', function () {
    $user = TodaySeed::user();

    roughSet($user, ['week_start' => '2026-09-26', 'rough' => true])->assertStatus(422);

    expect(PlanWeek::query()->where('is_rough', true)->count())->toBe(0);
});

it('SCH-063 CAP-013 marks this week rough with a toast, drops its days, and its undo unmarks it', function () {
    $user = TodaySeed::user();

    $response = roughSet($user, ['week_start' => '2026-09-19', 'rough' => true])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Marked as a rough week');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-19"}', 'week_show{"date":"2026-09-25"}', 'week_show{}', 'today_show{}')
        ->and(PlanWeek::query()->where('user_id', $user->id)->where('is_rough', true)->count())->toBe(1);

    $this->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $response->json('data.undo')], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(PlanWeek::query()->where('user_id', $user->id)->where('is_rough', true)->count())->toBe(0);
});
