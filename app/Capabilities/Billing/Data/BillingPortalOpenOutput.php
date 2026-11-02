<?php

namespace App\Capabilities\Billing\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** billing_portal_open's result: the write result (CAP-010) and the Stripe customer portal to open. */
final class BillingPortalOpenOutput extends CapabilityData
{
    /**
     * @param  list<string>  $invalidates  read keys whose data changed, as WriteResult (CAP-010)
     */
    public function __construct(
        #[Field(format: 'uri')]
        public readonly string $url,
        public readonly array $invalidates,
        public readonly ?string $toast = null,
        public readonly ?string $undo = null,
    ) {}
}
