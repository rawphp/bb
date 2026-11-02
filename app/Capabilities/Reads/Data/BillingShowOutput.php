<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Settings\BillingPlan;
use App\Capabilities\Reads\Data\Settings\LapsedBanner;
use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** billing_show: the subscription, the paywall and the lapsed banner (SUB-003, SUB-004, SUB-007, SUB-010). Registered only with billing on. */
final class BillingShowOutput extends CapabilityData
{
    /**
     * @param  list<BillingPlan>  $plans
     */
    public function __construct(
        public readonly Status $status,
        #[Field(description: 'null unless the subscription has lapsed')]
        public readonly ?LapsedBanner $lapsed,
        #[Field(items: BillingPlan::class)]
        public readonly array $plans,
        #[Field(enum: ['monthly', 'six_months', 'yearly'])]
        public readonly string $default_plan,
        #[Field(description: '"Start 7-day free trial" or "Subscribe"')]
        public readonly string $primary_label,
        public readonly string $terms_text,
        #[Field(description: 'the Stripe customer portal can open (SUB-007)')]
        public readonly bool $can_manage,
    ) {}
}
