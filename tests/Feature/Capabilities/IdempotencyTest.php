<?php

use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

beforeEach(function () {
    (new TallyAddCapability)->definition()->register(app(CapabilityRegistry::class));
    TallyAddCapability::$runs = 0;
    TallyAddCapability::$total = 0;
});

it('CAP-014 replays the first result for the same Idempotency-Key and input without running again', function () {
    $user = User::factory()->create();
    $key = ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7T8'];

    $first = $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $key)
        ->assertOk()
        ->assertJsonPath('data', ['invalidates' => ['tally_show{}'], 'toast' => 'Tally is 3', 'undo' => null]);

    $replay = $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $key)
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true);

    expect($replay->json('data'))->toEqual($first->json('data'));

    expect(TallyAddCapability::$runs)->toBe(1);
});

it('CAP-014 answers 422 for the same Idempotency-Key with different input', function () {
    $user = User::factory()->create();
    $key = ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7T8'];

    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $key)->assertOk();

    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 4], $key)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', 'This request was already sent with different details.')
        ->assertJsonPath('error.violations.0.field', 'idempotency_key');

    expect(TallyAddCapability::$runs)->toBe(1);
});
