<?php

namespace App\Capabilities\Billing\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** billing_portal_open's result: the write result (CAP-010) and the Stripe customer portal to open. */
final class BillingPortalOpenOutput extends CapabilityData
{
    /**
     * @param  list<string>  $invalidates
     */
    public function __construct(
        #[Field(description: 'read keys whose data changed (CAP-010)')]
        public readonly array $invalidates,
        public readonly ?string $toast,
        public readonly ?string $undo,
        #[Field(format: 'uri')]
        public readonly string $url,
    ) {}
}
