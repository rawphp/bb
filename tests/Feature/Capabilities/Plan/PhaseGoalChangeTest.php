<?php

use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\PhaseRules;
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
        ->and($hold->target_rate_percent_per_week)->toBe(PhaseRules::defaultRatePercentPerWeek(PhaseType::MuscleGain, ExperienceLevel::Intermediate))
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

it('PH-043 an event before the switch stays with the phase that ends', function () {
    [$user] = PlanSeed::world(event: '2026-10-15');

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => NEW_PHASE])->assertOk();

    expect(Phase::query()->findOrFail(W::CUT)->event_date->format('Y-m-d'))->toBe('2026-10-15')
        ->and(Phase::query()->findOrFail(NEW_PHASE)->event_date)->toBeNull();
});

it('PH-009 CAP-019 refuses a new_phase_id that names a phase this change did not create, and writes nothing', function () {
    [$user] = PlanSeed::world();

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => W::CUT])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'That new phase id is already used.');
    phaseGoalChange($user, ['phase_id' => W::HOLD, 'type' => 'muscleGain', 'new_phase_id' => W::GAIN])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'That new phase id is already used.');

    expect(Phase::query()->findOrFail(W::CUT)->end_date->format('Y-m-d'))->toBe('2026-11-23')
        ->and(Phase::query()->findOrFail(W::HOLD)->type->value)->toBe('maintenance');
});

it('PH-005 PH-009 an open-ended Maintenance changed to Fat loss gets the shortest recommended length and the default rate', function () {
    $user = TrainSeed::user();
    $hold = TrainSeed::phase($user, 'maintenance', '2026-08-31');

    phaseGoalChange($user, ['phase_id' => $hold->id, 'type' => 'fatLoss', 'new_phase_id' => NEW_PHASE])->assertOk();

    $new = Phase::query()->findOrFail(NEW_PHASE);
    expect($hold->fresh()->end_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and([$new->start_date->format('Y-m-d'), $new->end_date->format('Y-m-d')])->toBe(['2026-10-19', '2026-11-30'])
        ->and($new->target_rate_percent_per_week)->toBe(PhaseRules::defaultRatePercentPerWeek(PhaseType::FatLoss, ExperienceLevel::Intermediate));
});

it('PLAN-007 PH-009 in training-only mode the new phase has no rate', function () {
    [$user] = PlanSeed::world();
    $user->profile()->update(['training_only' => true]);

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'muscleGain', 'new_phase_id' => NEW_PHASE])->assertOk();

    $new = Phase::query()->findOrFail(NEW_PHASE);
    expect($new->target_rate_percent_per_week)->toBe(0.0)
        ->and($new->end_date->format('Y-m-d'))->toBe('2026-11-23')
        ->and(Phase::query()->findOrFail(W::CUT)->target_rate_percent_per_week)->toBe(-0.75);
});

it('PH-043 an event on the switch day goes with the new phase', function () {
    [$user] = PlanSeed::world(event: '2026-10-19');

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => NEW_PHASE])->assertOk();

    expect(Phase::query()->findOrFail(W::CUT)->event_date)->toBeNull()
        ->and(Phase::query()->findOrFail(NEW_PHASE)->event_date->format('Y-m-d'))->toBe('2026-10-19');
});

it('CAP-019 CAP-020 a new_phase_id that is another user\'s phase is not found, and neither user\'s rows change', function () {
    $other = TrainSeed::user();
    $theirs = Phase::factory()->for($other)->create(['id' => '01JB9Z0000000000000000000B', 'type' => 'muscleGain', 'start_date' => '2026-08-31', 'end_date' => '2026-12-21']);
    [$user] = PlanSeed::world();

    phaseGoalChange($user, ['phase_id' => W::CUT, 'type' => 'maintenance', 'new_phase_id' => $theirs->id])->assertNotFound();

    expect($theirs->fresh()->only(['user_id', 'type', 'start_date', 'end_date']))->toEqual($theirs->only(['user_id', 'type', 'start_date', 'end_date']))
        ->and(Phase::query()->findOrFail(W::CUT)->end_date->format('Y-m-d'))->toBe('2026-11-23');
});
