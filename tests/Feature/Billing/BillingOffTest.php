<?php

use App\Capabilities\Support\BillingGate;
use App\Capabilities\Support\NoBilling;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// The default: a self-hosted instance with BILLING_ENABLED unset.

it('SUB-020 registers no billing capability with billing off', function () {
    $billing = array_filter(array_keys(app(CapabilityRegistry::class)->all()), fn (string $name) => str_starts_with($name, 'billing_'));

    expect(config('billing.enabled'))->toBeFalse()
        ->and($billing)->toBe([]);
});

it('SUB-020 has no Stripe webhook route with billing off', function () {
    expect(Route::has('billing.webhook'))->toBeFalse();

    $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.deleted'])->assertStatus(405);
});

it('SUB-020 lets every signed-in user call everything with billing off, whatever a subscription row says', function () {
    expect(app(BillingGate::class))->toBeInstanceOf(NoBilling::class);

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'canceled']);

    $this->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => '01JBX8Z6Q7M0V2K3N4P5R6S7T8',
        'name' => 'Laptop MCP',
        'expires_in_days' => 90,
        'scope' => 'everything',
        'capabilities' => [],
    ])->assertOk();
});
