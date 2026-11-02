<?php

use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// phase_length_set over HTTP: the seam, and PH-006 and PLAN-021 on the database. What is offered is PhasePayloadTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function phaseLengthSet(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/phase_length_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function phaseDates(string $id): array
{
    $phase = Phase::query()->findOrFail($id);

    return [$phase->start_date?->format('Y-m-d'), $phase->end_date?->format('Y-m-d')];
}

it('PH-006 refuses phase_length_set when signed out', function () {
    phaseLengthSet(null, ['phase_id' => W::CUT, 'weeks' => 10, 'fix' => 'keep_end'])->assertUnauthorized();
});

it('PH-006 answers 404 for another user\'s phase', function () {
    PlanSeed::world();

    phaseLengthSet(TrainSeed::user(), ['phase_id' => W::CUT, 'weeks' => 10, 'fix' => 'keep_end'])->assertNotFound();
});

it('PH-006 answers 422 naming fix when it is not one of the three', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 10, 'fix' => 'stretch'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'fix');
});

it('PH-006 shortening the phase moves its end and the later phases with it', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 10, 'fix' => 'keep_end'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Phase is now 10 weeks')
        ->assertJsonPath('data.invalidates.0', 'plan_show{}');

    expect(phaseDates(W::CUT))->toBe(['2026-08-31', '2026-11-09'])
        ->and(phaseDates(W::HOLD))->toBe(['2026-11-09', '2026-12-07'])
        ->and(phaseDates(W::GAIN))->toBe(['2026-12-07', '2027-03-01']);
});

it('PH-006 lengthening the phase moves the later phases out', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 14, 'fix' => 'keep_end'])->assertOk();

    expect(phaseDates(W::CUT))->toBe(['2026-08-31', '2026-12-07'])
        ->and(phaseDates(W::HOLD))->toBe(['2026-12-07', '2027-01-04']);
});

it('PH-006 answers 422 in plain words for a length past TBL-010 or before the week the phase is in', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 20, 'fix' => 'keep_end'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'A Fat loss phase can run at most 16 weeks. Diet-break weeks do not count.');
    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 5, 'fix' => 'keep_end'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This phase is in week 7, so it must run at least 7 weeks.');

    expect(phaseDates(W::CUT))->toBe(['2026-08-31', '2026-11-23']);
});

it('PH-005 PH-006 a phase with phases after it cannot lose its end; the last Maintenance can', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::HOLD, 'weeks' => null, 'fix' => 'keep_end'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'A phase with no end cannot have phases after it.');

    Phase::query()->findOrFail(W::GAIN)->delete();
    phaseLengthSet($user, ['phase_id' => W::HOLD, 'weeks' => null, 'fix' => 'keep_end'])->assertOk();
    expect(phaseDates(W::HOLD))->toBe(['2026-11-23', null]);
});

it('PLAN-021 the user picks to move the end after the nearest deload', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 11, 'fix' => 'move_end_to_deload'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Phase is now 12 weeks');

    expect(phaseDates(W::CUT))->toBe(['2026-08-31', '2026-11-23'])
        ->and(Mesocycle::query()->findOrFail(W::MESO2)->weeks)->toBe(6);
});

it('PLAN-021 the user picks to change the mesocycle length so its deload is the last week', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 11, 'fix' => 'change_mesocycle_length'])->assertOk();

    expect(phaseDates(W::CUT))->toBe(['2026-08-31', '2026-11-16'])
        ->and(Mesocycle::query()->findOrFail(W::MESO2)->weeks)->toBe(5);
});

it('PLAN-021 a fix that does not fit is refused, and nothing changes', function () {
    [$user] = PlanSeed::world();

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 8, 'fix' => 'move_end_to_deload'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'No deload falls within 2 weeks of that end, so it cannot move there.');

    expect(phaseDates(W::CUT))->toBe(['2026-08-31', '2026-11-23']);
});

it('PLAN-025 a deload with its diet break off counts towards the sixteen-week cap', function () {
    [$user] = PlanSeed::world();
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-11-16', 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false, 'diet_break_off' => true]);

    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 19, 'fix' => 'keep_end'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'A Fat loss phase can run at most 16 weeks. Diet-break weeks do not count.');
    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 18, 'fix' => 'keep_end'])->assertOk();
});

it('PH-006 refuses a length past 52 weeks, and one that ends the running phase this week', function () {
    [$user] = PlanSeed::world();
    Phase::query()->findOrFail(W::GAIN)->delete();

    phaseLengthSet($user, ['phase_id' => W::HOLD, 'weeks' => 53, 'fix' => 'keep_end'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'A phase can run at most 52 weeks.');
    phaseLengthSet($user, ['phase_id' => W::CUT, 'weeks' => 7, 'fix' => 'keep_end'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'That ends the phase this week. Use End phase, which asks first.');
});
