<?php

use App\Domain\Billing\BillingRule;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\BillingOn;
use Tests\Support\RecordingLlmClient;

uses()->group('warp-isolated', BillingOn::GROUP);

beforeEach(fn () => Http::preventStrayRequests());

it('SUB-001 every AI scan the billing rule names is a registered read', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (BillingRule::AI_READS as $name) {
        expect($registry->get($name)->readOnly)->toBeTrue("{$name} is not a registered read");
    }
});

it('SUB-022 SUB-003 with billing on, a user with no plan is refused an AI scan and nothing is sent', function () {
    $llm = RecordingLlmClient::answering(['items' => []])->install();

    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/scan_describe', ['description' => 'toast'])
        ->assertForbidden()
        ->assertJsonPath('ok', false);

    expect($llm->calls)->toBe([]);
});

it('SUB-001 with billing on, a user on the trial can scan', function () {
    RecordingLlmClient::answering(['items' => []])->install();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'trialing', 'plan' => 'monthly', 'trial_ends_at' => now()->addDays(5)]);

    $this->actingAs($user)
        ->postJson('/capabilities/scan_describe', ['description' => 'toast'])
        ->assertOk();
});

it('SUB-010 with billing on, a user with no plan can still adjust a scan and look up a barcode', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/scan_adjust', ['rows' => [], 'from_portion' => 1, 'portion' => 1])->assertOk();
    $this->actingAs($user)->postJson('/capabilities/food_barcode_lookup', ['barcode' => '123'])->assertOk();
});
