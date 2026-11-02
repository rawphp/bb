<?php

namespace App\Capabilities\Reads\Data\Settings;

use App\Capabilities\Support\SettingsValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A plan the paywall offers (SUB-002), with its price. */
final class BillingPlan extends CapabilityData
{
    public function __construct(
        #[Field(enum: SettingsValues::BILLING_PLANS)]
        public readonly string $plan,
        public readonly string $title,
        #[Field(description: 'for example "$34.99 per month"')]
        public readonly string $price_text,
        public readonly ?string $detail,
    ) {}
}
