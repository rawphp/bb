<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// The HTTP seam of mesocycle_end. The rules are unit tests (MesocycleCalendarTest, EndMesocycleTest).

afterEach(fn () => Carbon::setTestNow());

function endOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/mesocycle_end', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('AGT-010 MESO-043 mesocycle_end is destructive and not reversible', function () {
    $definition = app(CapabilityRegistry::class)->get('mesocycle_end');

    expect(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and(HostDefinition::isReversible($definition))->toBeFalse();
});

it('MESO-043 refuses mesocycle_end when signed out', function () {
    endOver(null, ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5'])->assertUnauthorized();
});

it('MESO-043 answers 404 for another user\'s mesocycle', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());

    endOver(TrainSeed::user(), ['mesocycle_id' => $theirs->id])->assertNotFound();
});

it('MESO-044 answers 422 naming diet_break when it is not a yes or no', function () {
    $user = TrainSeed::user();

    endOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'diet_break' => 'yes'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'diet_break');
});

it('MESO-043 MESO-044 ends the mesocycle today with a diet break next week', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $cut = TrainSeed::phase($user, 'fatLoss', '2026-08-31');
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $cut->id]);

    endOver($user, ['mesocycle_id' => $mesocycle->id, 'diet_break' => true])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['train_show{}', "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}"])
        ->assertJsonPath('data.toast', 'Mesocycle ended. Next week is a diet break.');

    expect($mesocycle->fresh()->ended_on->format('Y-m-d'))->toBe('2026-09-23');
});
