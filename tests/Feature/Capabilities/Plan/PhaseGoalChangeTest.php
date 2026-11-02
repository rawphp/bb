<?php

use App\Models\Phase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// phase_goal_change over HTTP: the seam, and PH-009 on the database. The preview is PhasePayloadTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function phaseGoalChange(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/phase_goal_change', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

const NEW_PHASE = '01JB9Z0000000000000000000A';

it('PH-009 refuses phase_goal_change when signed out', function () {
    phaseGoalChange(null, ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => NEW_PHASE])->assertUnauthorized();
});

it('PH-009 answers 404 for another user\'s phase', function () {
    PlanSeed::world();

    phaseGoalChange(TrainSeed::user(), ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => NEW_PHASE])->assertNotFound();
});

it('PH-009 answers 422 naming new_phase_id when it is not a ULID', function () {
    [$user] = PlanSeed::world();

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'new_phase_id');
});

it('PH-009 a new goal starts next week: the running phase ends there and the new one takes its weeks and its event', function () {
    [$user] = PlanSeed::world(event: '2026-11-15');

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => NEW_PHASE])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Goal is now Maintenance');

    $cut = Phase::query()->findOrFail(W::CUT);
    $new = Phase::query()->findOrFail(NEW_PHASE);
    expect($cut->end_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and($cut->event_date)->toBeNull()
        ->and($new->type->value)->toBe('maintenance')
        ->and([$new->start_date->format('Y-m-d'), $new->end_date->format('Y-m-d')])->toBe(['2026-10-19', '2026-11-23'])
        ->and($new->target_rate_percent_per_week)->toBe(0.0)
        ->and($new->event_date->format('Y-m-d'))->toBe('2026-11-15')
        ->and(Phase::query()->findOrFail(W::HOLD)->start_date->format('Y-m-d'))->toBe('2026-11-23');
});

it('PH-009 a replayed change finds its new phase already written', function () {
    [$user] = PlanSeed::world();
    $input = ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => NEW_PHASE];

    phaseGoalChange($user, $input)->assertOk();
    phaseGoalChange($user, $input)->assertOk();

    expect(Phase::query()->where('user_id', $user->id)->count())->toBe(4);
});

it('PH-009 a phase that has not started changes type in place', function () {
    [$user] = PlanSeed::world();

    phaseGoalChange($user, ['phase_id' => W::HOLD, 'type' => 'muscleGain', 'new_phase_id' => NEW_PHASE])->assertOk();

    $hold = Phase::query()->findOrFail(W::HOLD);
    expect($hold->type->value)->toBe('muscleGain')
        ->and($hold->end_date->format('Y-m-d'))->toBe('2026-12-21')
        ->and(Phase::query()->find(NEW_PHASE))->toBeNull();
});

it('PH-009 a goal the weeks left cannot hold is refused with TBL-010\'s reason', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
    [$user] = PlanSeed::world();

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'miniCut', 'new_phase_id' => NEW_PHASE])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'A Mini-cut phase can run at most 6 weeks. Diet-break weeks do not count.');
});
