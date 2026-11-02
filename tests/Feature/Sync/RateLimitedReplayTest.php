<?php

// Feature, not unit: the refusal comes from the package's rate-limit stage, after the
// idempotency claim and before the run, and only the real pipeline and store show what
// a resend under the same key gets once the limit allows it.

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

beforeEach(function () {
    (new TallyAddCapability)->definition()
        ->rateLimit(['per_capability_per_minute' => 1])
        ->register(app(CapabilityRegistry::class));
    TallyAddCapability::$runs = 0;
    TallyAddCapability::$total = 0;
});

it('OFF-011 runs a write the rate limit refused before it ran once the same key is sent after the limit', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], ['Idempotency-Key' => 'tally_add:first'])->assertOk();

    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 4], ['Idempotency-Key' => 'tally_add:second'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.retryable', true);
    expect(TallyAddCapability::$runs)->toBe(1);

    // The minute passes.
    Cache::flush();
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 4], ['Idempotency-Key' => 'tally_add:second'])
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', false);

    expect(TallyAddCapability::$runs)->toBe(2)
        ->and(TallyAddCapability::$total)->toBe(7);
});

it('CAP-014 keeps the stored answer of a write that ran when its resend is rate limited', function () {
    $user = User::factory()->create();
    $key = ['Idempotency-Key' => 'tally_add:ran'];
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $key)->assertOk();

    // The answer was lost, and the resend lands inside the same minute.
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $key)->assertStatus(429);
    Cache::flush();
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $key)
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true);

    expect(TallyAddCapability::$runs)->toBe(1);
});
