<?php

namespace App\Capabilities\Billing;

use App\Actions\Billing\StartCheckout;
use App\Capabilities\Billing\Data\BillingCheckoutStartInput;
use App\Capabilities\Billing\Data\BillingCheckoutStartOutput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\BillingOnly;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class BillingCheckoutStartCapability implements BillingOnly, HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        // The subscription changes when Stripe's webhook reports it, so billing_show is read again on return.
        return HostDefinition::write('billing_checkout_start', affects: ['billing_show'])
            ->description('Open Stripe Checkout to subscribe to a plan: monthly, six_months or yearly. A user who has never subscribed gets the 7-day free trial; promotion codes are typed in Checkout. Returns the Checkout page to open. Only a person signed in to the app can call it (CAP-031).')
            ->cli('billing', 'checkout')
            ->input(BillingCheckoutStartInput::class)
            ->output(BillingCheckoutStartOutput::class)
            ->run(fn (BillingCheckoutStartInput $input, CapabilityContext $context): BillingCheckoutStartOutput => $this->run($input, $context));
    }

    private function run(BillingCheckoutStartInput $input, CapabilityContext $context): BillingCheckoutStartOutput
    {
        /** @var User $user */
        $user = $context->user();

        // Resolved per call rather than at boot, so the Stripe client is made only when a call needs it.
        $url = app(StartCheckout::class)($user, $input->plan);

        return new BillingCheckoutStartOutput(url: $url, result: new WriteResult(invalidates: [ReadKey::for('billing_show')]));
    }
}
