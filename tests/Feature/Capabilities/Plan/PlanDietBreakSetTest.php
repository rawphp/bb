<?php

use App\Models\Phase;
use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PlanSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// plan_diet_break_set over HTTP: the seam, and PLAN-023 on the database. What is offered is PlanPayloadTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function dietBreakSet(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/plan_diet_break_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('PLAN-023 refuses plan_diet_break_set when signed out', function () {
    dietBreakSet(null, ['week_start' => '2026-11-16', 'diet_break' => false])->assertUnauthorized();
});

it('PLAN-023 answers 422 naming week_start when it is not a date', function () {
    dietBreakSet(User::factory()->create(), ['week_start' => 'soon', 'diet_break' => false])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'week_start');
});

it('PLAN-023 changes only the user\'s own plan: another user\'s deload week is not theirs to turn off', function () {
    PlanSeed::world();

    dietBreakSet(User::factory()->create(), ['week_start' => '2026-11-16', 'diet_break' => false])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'There is no plan yet.');
    expect(PlanWeek::query()->count())->toBe(0);
});

it('PLAN-023 turning a Fat loss deload\'s diet break off saves that one week, and turning it back on keeps the row', function () {
    [$user] = PlanSeed::world();

    dietBreakSet($user, ['week_start' => '2026-11-16', 'diet_break' => false])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Deload now in a deficit');
    $row = PlanWeek::query()->where('user_id', $user->id)->sole();
    expect($row->start_date->format('Y-m-d'))->toBe('2026-11-16')
        ->and($row->diet_break_off)->toBeTrue()
        ->and($row->is_deload)->toBeFalse();

    $deloads = $this->actingAs($user)->postJson('/capabilities/plan_show')->json('data.deloads');
    expect($deloads[1]['marker'])->toBe('deficit_deload');

    dietBreakSet($user, ['week_start' => '2026-11-16', 'diet_break' => true])->assertOk();
    expect($row->fresh()->diet_break_off)->toBeFalse()
        ->and(PlanWeek::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('PLAN-023 only a Fat loss or Mini-cut deload still ahead can be turned off', function () {
    [$user] = PlanSeed::world();

    dietBreakSet($user, ['week_start' => '2026-10-05', 'diet_break' => false])
        ->assertStatus(422)->assertJsonPath('error.message', 'That deload is over, so its calories can no longer change.');
    dietBreakSet($user, ['week_start' => '2026-12-14', 'diet_break' => false])
        ->assertStatus(422)->assertJsonPath('error.message', 'Only a Fat loss or Mini-cut deload has a diet break to turn off.');
    dietBreakSet($user, ['week_start' => '2026-10-26', 'diet_break' => false])
        ->assertStatus(422)->assertJsonPath('error.message', 'That week is not a deload on your plan.');
    expect(PlanWeek::query()->count())->toBe(0);
});

it('PLAN-023 a week that has a row keeps its other flags', function () {
    [$user] = PlanSeed::world();
    $row = PlanWeek::factory()->for($user)->create(['start_date' => '2026-11-16', 'is_deload' => true, 'is_diet_break' => true, 'is_away' => false]);

    dietBreakSet($user, ['week_start' => '2026-11-16', 'diet_break' => false])->assertOk();

    $row->refresh();
    expect($row->diet_break_off)->toBeTrue()
        ->and($row->is_deload)->toBeTrue()
        ->and($row->is_diet_break)->toBeTrue()
        ->and(PlanWeek::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('PLAN-023 a date inside the week is not a deload week start', function () {
    [$user] = PlanSeed::world();

    dietBreakSet($user, ['week_start' => '2026-11-17', 'diet_break' => false])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'That week is not a deload on your plan.');
    expect(PlanWeek::query()->count())->toBe(0);
});

it('PLAN-025 CAP-011 turning a diet break off names the phase reads and that week\'s days as changed', function () {
    [$user] = PlanSeed::world();

    $keys = dietBreakSet($user, ['week_start' => '2026-11-16', 'diet_break' => false])->assertOk()->json('data.invalidates');

    expect($keys)->toContain('phase_show{"phase_id":"'.W::CUT.'"}')
        ->toContain('today_show{"date":"2026-11-16"}')
        ->toContain('week_show{"date":"2026-11-22"}');
});

it('PLAN-007 PLAN-023 training-only deloads have no diet break to turn off', function () {
    [$user] = PlanSeed::world();
    $user->profile()->update(['training_only' => true]);

    dietBreakSet($user, ['week_start' => '2026-11-16', 'diet_break' => false])
        ->assertStatus(422)->assertJsonPath('error.message', 'Only a Fat loss or Mini-cut deload has a diet break to turn off.');
});

it('PLAN-009 PLAN-023 a Mini-cut deload can be turned off, and one already on cannot be turned on again', function () {
    [$user] = PlanSeed::world();
    Phase::query()->findOrFail(W::CUT)->update(['type' => 'miniCut']);

    dietBreakSet($user, ['week_start' => '2026-11-16', 'diet_break' => true])
        ->assertStatus(422)->assertJsonPath('error.message', 'This deload already runs at maintenance calories.');
    dietBreakSet($user, ['week_start' => '2026-11-16', 'diet_break' => false])->assertOk();
    expect(PlanWeek::query()->where('user_id', $user->id)->sole()->diet_break_off)->toBeTrue();
});
