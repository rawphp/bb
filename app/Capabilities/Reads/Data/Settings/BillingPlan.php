<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A plan the paywall offers (SUB-002), with its price. */
final class BillingPlan extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['monthly', 'six_months', 'yearly'])]
        public readonly string $plan,
        public readonly string $title,
        #[Field(description: 'for example "$34.99 per month"')]
        public readonly string $price_text,
        public readonly ?string $detail,
    ) {}
}
