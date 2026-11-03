<?php

use App\Capabilities\Plan\PlanReads;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// time_zone_set over HTTP: SET-012's setter, sent by the app from the device on each load.

afterEach(fn () => Carbon::setTestNow());

function timeZoneSet(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/time_zone_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SET-012 registers time_zone_set on every host surface as a write that changes the plan reads', function () {
    $definition = app(CapabilityRegistry::class)->get('time_zone_set');

    expect($definition->surfaces)->toEqualCanonicalizing(HostDefinition::SURFACES)
        ->and($definition->readOnly)->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(PlanReads::AFFECTS)
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and($definition->description)->not->toBe('');
});

it('SET-012 refuses time_zone_set when signed out', function () {
    timeZoneSet(null, ['time_zone' => 'Australia/Brisbane'])->assertUnauthorized();
});

it('SET-012 starts every user on UTC', function () {
    $user = User::factory()->create();

    expect($user->fresh()->time_zone)->toBe('UTC');
});

it('SET-012 stores a new time zone and invalidates the plan reads', function () {
    $user = User::factory()->create();

    $response = timeZoneSet($user, ['time_zone' => 'Australia/Brisbane'])->assertOk();

    expect($user->fresh()->time_zone)->toBe('Australia/Brisbane')
        ->and($response->json('data.invalidates'))->toBe(PlanReads::keys($user));
});

it('SET-012 answers 422 naming time_zone for a name that is not an IANA zone, and keeps the stored one', function (string $zone) {
    $user = User::factory()->create(['time_zone' => 'America/New_York']);

    timeZoneSet($user, ['time_zone' => $zone])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'time_zone');
    expect($user->fresh()->time_zone)->toBe('America/New_York');
})->with(['offset' => '+10:00', 'abbreviation' => 'AEST', 'empty' => '', 'made up' => 'Mars/Olympus_Mons']);

it('SET-012 sending the zone the user already has changes nothing', function () {
    Carbon::setTestNow('2026-10-01 08:00:00');
    $user = User::factory()->create(['time_zone' => 'Australia/Brisbane']);
    $stamp = $user->fresh()->updated_at->toIso8601String();
    Carbon::setTestNow('2026-10-02 08:00:00');

    timeZoneSet($user, ['time_zone' => 'Australia/Brisbane'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', []);
    expect($user->fresh()->updated_at->toIso8601String())->toBe($stamp);
});
