<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\PlanSeed;
use Tests\Support\TrainSeed;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// phase_end over HTTP: the seam, and PH-007 on the database.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function phaseEnd(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/phase_end', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('PH-007 refuses phase_end when signed out', function () {
    phaseEnd(null, ['phase_id' => W::CUT])->assertUnauthorized();
});

it('PH-007 answers 404 for another user\'s phase', function () {
    PlanSeed::world();

    phaseEnd(TrainSeed::user(), ['phase_id' => W::CUT])->assertNotFound();
});

it('PH-007 answers 422 naming phase_id when it is missing', function () {
    phaseEnd(User::factory()->create(), [])->assertStatus(422)->assertJsonPath('error.violations.0.field', 'phase_id');
});

it('PH-007 AGT-010 ending a phase is destructive', function () {
    expect(HostDefinition::isDestructive(app(CapabilityRegistry::class)->get('phase_end')))->toBeTrue();
});

it('PH-007 ending the running phase ends it at the next plan week, and the later phases move up', function () {
    [$user] = PlanSeed::world();

    $keys = phaseEnd($user, ['phase_id' => W::CUT])->assertOk()->assertJsonPath('data.toast', 'Phase ends this week')->json('data.invalidates');

    expect($keys)->toContain('phase_show{"phase_id":"'.W::CUT.'"}')
        ->toContain('phase_show{"phase_id":"'.W::HOLD.'"}')
        ->toContain('phase_show{"phase_id":"'.W::GAIN.'"}');

    $cut = Phase::query()->findOrFail(W::CUT);
    $hold = Phase::query()->findOrFail(W::HOLD);
    expect($cut->end_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and($hold->start_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and($hold->end_date->format('Y-m-d'))->toBe('2026-11-16');
});

it('PH-007 a phase that is not running cannot be ended', function () {
    [$user] = PlanSeed::world();

    phaseEnd($user, ['phase_id' => W::HOLD])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Only the phase running now can be ended.');
});

it('PH-007 ending a phase in its last week keeps its end and the later phases where they are', function () {
    [$user] = PlanSeed::world();
    Phase::query()->findOrFail(W::CUT)->update(['end_date' => '2026-10-19']);
    Phase::query()->findOrFail(W::HOLD)->update(['start_date' => '2026-10-19']);

    $keys = phaseEnd($user, ['phase_id' => W::CUT])->assertOk()->assertJsonPath('data.toast', 'Phase ends this week')->json('data.invalidates');

    expect(Phase::query()->findOrFail(W::CUT)->end_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and(Phase::query()->findOrFail(W::HOLD)->start_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and(Phase::query()->findOrFail(W::HOLD)->end_date->format('Y-m-d'))->toBe('2026-12-21')
        ->and($keys)->toContain('phase_show{"phase_id":"'.W::CUT.'"}');
});

it('PH-005 PH-007 ending a running Maintenance with no end gives it an end at the next plan week', function () {
    $user = TrainSeed::user();
    $hold = TrainSeed::phase($user, 'maintenance', '2026-08-31');

    phaseEnd($user, ['phase_id' => $hold->id])->assertOk();

    expect($hold->fresh()->end_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and($hold->fresh()->start_date->format('Y-m-d'))->toBe('2026-08-31');
});

it('PH-007 AGT-010 the phase_end description promises no transition this PR does not open', function () {
    $description = app(CapabilityRegistry::class)->get('phase_end')->description;

    expect($description)->not->toContain('transition follows')
        ->and($description)->toContain('cannot be undone');
});
