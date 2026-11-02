<?php

namespace App\Capabilities\Billing\Data;

use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;
use Rawphp\Capabilities\Attributes\Field;

/** billing_checkout_start's result: the write result (CAP-010) and the Checkout page to open. */
final class BillingCheckoutStartOutput extends WriteOutput
{
    public function __construct(
        #[Field(format: 'uri')]
        public readonly string $url,
        public readonly WriteResult $result,
    ) {}
}
