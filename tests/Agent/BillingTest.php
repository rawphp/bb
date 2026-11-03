<?php

use App\Agent\RegistryToolCatalog;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Agent\Coach;
use Tests\Support\BillingOn;

// An eval request for billing, which needs a person (CAP-031, AGT-050). Billing is on, as the hosted instance runs,
// so its capabilities are registered and only the exclusion keeps them from the Coach.
uses()->group('warp-isolated', BillingOn::GROUP);

it('AGT-050 AGT-001 a request for an excluded capability: "cancel my subscription"', function () {
    $user = User::factory()->create();

    Coach::says($user, 'Cancel my subscription', refused: [['billing_portal_open', []]],
        reply: 'I can\'t manage billing. You can cancel in Settings, under Subscription.');

    expect(app(CapabilityRegistry::class)->has('billing_portal_open'))->toBeTrue()
        ->and(app(RegistryToolCatalog::class)->names())->not->toContain('billing_portal_open');
});
