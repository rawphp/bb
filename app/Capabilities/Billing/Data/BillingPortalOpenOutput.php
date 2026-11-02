<?php

namespace App\Capabilities\Billing\Data;

use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;
use Rawphp\Capabilities\Attributes\Field;

/** billing_portal_open's result: the write result (CAP-010) and the Stripe customer portal to open. */
final class BillingPortalOpenOutput extends WriteOutput
{
    public function __construct(
        #[Field(format: 'uri')]
        public readonly string $url,
        public readonly WriteResult $result,
    ) {}
}
