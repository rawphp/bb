<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// The HTTP seam of the create flow's writes and priority_set. The rules are unit tests (BuildMesocycleTest,
// DraftStructureTest, SetMusclePriorityTest). Muscle gain covers everything from 31 August.

afterEach(fn () => Carbon::setTestNow());

function flowOver(?User $user, string $capability, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$capability}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function flowUser(): User
{
    Carbon::setTestNow('2026-10-07 10:00:00');
    $user = TrainSeed::user();
    TrainSeed::phase($user, 'muscleGain', '2026-08-31');

    return $user;
}

/** Each write, the input that names `$id`, and the field one bad value is reported on. */
dataset('build writes', [
    'mesocycle_build' => ['mesocycle_build', fn (string $id) => ['mesocycle_id' => $id, 'name' => 'Block'], ['mesocycle_id' => (string) Str::ulid(), 'route' => 'copy'], 'route'],
    'mesocycle_structure_generate' => ['mesocycle_structure_generate', fn (string $id) => ['mesocycle_id' => $id], [], 'mesocycle_id'],
    'mesocycle_slots_fill' => ['mesocycle_slots_fill', fn (string $id) => ['mesocycle_id' => $id], [], 'mesocycle_id'],
    'priority_set' => ['priority_set', fn (string $id) => ['mesocycle_id' => $id, 'muscle' => 'chest', 'priority' => 'grow'], ['mesocycle_id' => (string) Str::ulid(), 'muscle' => 'lats', 'priority' => 'grow'], 'muscle'],
]);

it('MESO-007 MESO-013 MESO-022 MESO-032 refuses each write when signed out', function (string $capability, Closure $input) {
    flowOver(null, $capability, $input((string) Str::ulid()))->assertUnauthorized();
})->with('build writes');

it('MESO-007 MESO-013 MESO-022 MESO-032 answers 404 for another user\'s build, and changes nothing', function (string $capability, Closure $input) {
    $theirs = TrainSeed::mesocycle(User::factory()->create(), ['is_draft' => true, 'route' => 'builder', 'days' => []]);
    $before = $theirs->fresh()->getAttributes();

    flowOver(flowUser(), $capability, $input($theirs->id))->assertNotFound();

    expect($theirs->fresh()->getAttributes())->toBe($before);
})->with('build writes');

it('MESO-007 MESO-013 MESO-022 MESO-032 answers 422 naming the bad field', function (string $capability, Closure $input, array $bad, string $field) {
    flowOver(flowUser(), $capability, $bad)
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with('build writes');

it('CAP-005 CAP-011 CAP-013 AGT-010 declares each write\'s surfaces, CLI verb, reads and flags', function (string $name, array $cli, bool $reversible, bool $destructive) {
    $definition = app(CapabilityRegistry::class)->get($name);

    expect($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and([$definition->cliDomain, $definition->cliVerb])->toBe($cli)
        ->and(HostDefinition::affects($definition))->toBe(['train_show', 'mesocycle_show'])
        ->and(HostDefinition::isReversible($definition))->toBe($reversible)
        ->and(HostDefinition::isDestructive($definition))->toBe($destructive)
        ->and(substr_count($definition->description, '. '))->toBeGreaterThanOrEqual(1);
})->with([
    // A new build deletes the other unfinished one (MESO-007), which cannot be undone.
    'mesocycle_build' => ['mesocycle_build', ['mesocycle', 'build'], false, true],
    'mesocycle_structure_generate' => ['mesocycle_structure_generate', ['mesocycle', 'structure-generate'], true, false],
    'mesocycle_slots_fill' => ['mesocycle_slots_fill', ['mesocycle', 'slots-fill'], true, false],
    'priority_set' => ['priority_set', ['mesocycle', 'priority-set'], true, false],
]);

it('MESO-007 MESO-022 MESO-013 builds, lays out, fills and saves a mesocycle, naming the reads each step changed', function () {
    $user = flowUser();
    $old = TrainSeed::mesocycle($user, ['is_draft' => true]);
    Exercise::factory()->create(['name' => 'Pec Deck', 'muscle_group' => 'chest']);
    $id = (string) Str::ulid();
    $mine = fn () => ['train_show{}', "mesocycle_show{\"mesocycle_id\":\"{$id}\"}"];

    flowOver($user, 'mesocycle_build', ['mesocycle_id' => $id, 'route' => 'builder', 'days_per_week' => 2])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [...$mine(), "mesocycle_show{\"mesocycle_id\":\"{$old->id}\"}"])
        ->assertJsonPath('data.toast', null);
    flowOver($user, 'mesocycle_structure_generate', ['mesocycle_id' => $id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', $mine());
    flowOver($user, 'mesocycle_slots_fill', ['mesocycle_id' => $id])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Picked 2 exercises');
    flowOver($user, 'mesocycle_build', ['mesocycle_id' => $id, 'name' => 'Autumn block', 'finish' => true])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Autumn block saved');

    expect(Mesocycle::query()->findOrFail($id)->isSaved())->toBeTrue()
        ->and($old->fresh()->trashed())->toBeTrue();
});

it('MESO-007 a build that cannot finish says why with 422', function () {
    $user = flowUser();
    $id = (string) Str::ulid();
    flowOver($user, 'mesocycle_build', ['mesocycle_id' => $id, 'route' => 'scratch']);

    flowOver($user, 'mesocycle_build', ['mesocycle_id' => $id, 'finish' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Give the mesocycle a name first.');
});

it('MESO-013 answers 404 for another user\'s custom exercise in a slot', function () {
    $theirs = Exercise::factory()->custom()->create(['muscle_group' => 'chest']);

    flowOver(flowUser(), 'mesocycle_build', ['mesocycle_id' => (string) Str::ulid(), 'route' => 'scratch', 'days' => [
        ['day_id' => (string) Str::ulid(), 'slots' => [['slot_id' => (string) Str::ulid(), 'muscle' => 'chest', 'exercise_id' => (string) $theirs->id, 'sets' => 3]]],
    ]])->assertNotFound();
});

it('MESO-032 MESO-034 CAP-013 sets a running mesocycle\'s muscle Off from the next session, and undo puts it back', function () {
    $user = flowUser();
    $mesocycle = TrainSeed::mesocycle($user);

    $token = flowOver($user, 'priority_set', ['mesocycle_id' => $mesocycle->id, 'muscle' => 'quads', 'priority' => null])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Quads is Off from the next session')
        ->json('data.undo');

    expect($mesocycle->fresh()->muscle_priorities['quads'])->toBeNull()
        ->and($token)->toBeString();

    flowOver($user, 'undo_apply', ['token' => $token])->assertOk();

    expect($mesocycle->fresh()->muscle_priorities['quads'])->toBe('grow');
});
