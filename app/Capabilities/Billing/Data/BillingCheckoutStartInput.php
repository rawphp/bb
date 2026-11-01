<?php

namespace App\Capabilities\Billing\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** billing_checkout_start: the plan to buy in Stripe Checkout (SUB-005). */
final class BillingCheckoutStartInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['monthly', 'six_months', 'yearly'])]
        public readonly string $plan,
    ) {}
}
