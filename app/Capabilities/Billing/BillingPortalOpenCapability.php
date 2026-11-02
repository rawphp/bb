<?php

namespace App\Capabilities\Billing;

use App\Actions\Billing\OpenPortal;
use App\Capabilities\Billing\Data\BillingPortalOpenInput;
use App\Capabilities\Billing\Data\BillingPortalOpenOutput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\BillingOnly;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class BillingPortalOpenCapability implements BillingOnly, HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        // Changes made in the portal reach the app through Stripe's webhook, so billing_show is read again on return.
        return HostDefinition::write('billing_portal_open', affects: ['billing_show'])
            ->description('Open the Stripe customer portal, where the user switches plan, updates their card or cancels. Returns the portal page to open; refused when the user has never subscribed. Only a person signed in to the app can call it (CAP-031).')
            ->cli('billing', 'portal')
            ->input(BillingPortalOpenInput::class)
            ->output(BillingPortalOpenOutput::class)
            ->run(fn (BillingPortalOpenInput $input, CapabilityContext $context): BillingPortalOpenOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): BillingPortalOpenOutput
    {
        /** @var User $user */
        $user = $context->user();

        // Resolved per call rather than at boot, so the Stripe client is made only when a call needs it.
        $url = app(OpenPortal::class)($user);

        return new BillingPortalOpenOutput(url: $url, result: new WriteResult(invalidates: [ReadKey::for('billing_show')]));
    }
}
