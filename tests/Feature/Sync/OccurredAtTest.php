<?php

// Feature, not unit: proves the capability route runs AcceptOccurredAt before the
// write, after the walked clock. The window itself is unit tested.

use App\Models\User;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

beforeEach(function () {
    (new TallyAddCapability)->definition()->register(app(CapabilityRegistry::class));
    TallyAddCapability::$runs = 0;
    Carbon::setTestNow('2026-11-02T09:00:00Z');
});

afterEach(fn () => Carbon::setTestNow());

it('OFF-007 refuses a write made more than 7 days ago, before it runs', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/tally_add', ['amount' => 3], ['Idempotency-Key' => 'tally_add:old', 'X-BB-Occurred-At' => '2026-10-25T09:00:00Z'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'occurred_at');

    expect(TallyAddCapability::$runs)->toBe(0);
});

it('OFF-007 judges occurred_at against the walked clock (SET-011)', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/tally_add', ['amount' => 3], ['X-BB-Now' => '2026-11-20T09:00:00Z', 'X-BB-Occurred-At' => '2026-11-20T08:00:00Z'])
        ->assertOk();

    expect(TallyAddCapability::$runs)->toBe(1);
});

it('CAP-014 answers a lost write sent again after its window from the stored answer, not a refusal', function () {
    $user = User::factory()->create();
    $headers = ['Idempotency-Key' => 'tally_add:late', 'X-BB-Occurred-At' => '2026-11-02T08:00:00Z'];
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $headers)->assertOk();

    // The answer was lost, and the outbox sends it again eight days on.
    Carbon::setTestNow('2026-11-10T09:00:00Z');
    $this->actingAs($user)->postJson('/capabilities/tally_add', ['amount' => 3], $headers)
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true);

    expect(TallyAddCapability::$runs)->toBe(1);
});
