<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// The HTTP seam of mesocycle_copy. The rules are unit tests (CopyMesocycleTest).

afterEach(fn () => Carbon::setTestNow());

function copyOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/mesocycle_copy', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('AGT-010 MESO-007 mesocycle_copy is destructive, since it replaces the other draft, and says so', function () {
    $definition = app(CapabilityRegistry::class)->get('mesocycle_copy');

    expect(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and($definition->description)->toContain('deletes');
});

it('MESO-060 refuses mesocycle_copy when signed out', function () {
    copyOver(null, ['mesocycle_id' => (string) Str::ulid(), 'source_mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5'])->assertUnauthorized();
});

it('MESO-060 answers 404 for another user\'s mesocycle, as the source or as the new id', function () {
    $other = User::factory()->create();
    $theirs = TrainSeed::mesocycle($other);
    $theirDraft = TrainSeed::mesocycle($other, ['id' => (string) Str::ulid(), 'is_draft' => true]);
    $user = TrainSeed::user();
    $mine = TrainSeed::mesocycle($user);

    copyOver($user, ['mesocycle_id' => (string) Str::ulid(), 'source_mesocycle_id' => $theirs->id])->assertNotFound();
    copyOver($user, ['mesocycle_id' => $theirDraft->id, 'source_mesocycle_id' => $mine->id])->assertNotFound();
});

it('CAP-019 answers 422 naming mesocycle_id when it is not a ULID', function () {
    $user = TrainSeed::user();

    copyOver($user, ['mesocycle_id' => 'copy-1', 'source_mesocycle_id' => TrainSeed::mesocycle($user)->id])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'mesocycle_id');
});

it('MESO-060 MESO-007 copies into the draft the client named and says which reads changed', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $source = TrainSeed::mesocycle($user);
    $old = TrainSeed::mesocycle($user, ['is_draft' => true]);
    $id = (string) Str::ulid();

    copyOver($user, ['mesocycle_id' => $id, 'source_mesocycle_id' => $source->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'train_show{}',
            "mesocycle_show{\"mesocycle_id\":\"{$id}\"}",
            "mesocycle_show{\"mesocycle_id\":\"{$old->id}\"}",
        ])
        ->assertJsonPath('data.toast', 'Copied into a new draft');

    expect(Mesocycle::query()->find($id)?->is_draft)->toBeTrue()
        ->and($old->fresh()->trashed())->toBeTrue();
});
