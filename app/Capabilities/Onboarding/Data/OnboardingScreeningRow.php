<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One SAFE-001 item. A locked row was worked out from step 2 (age, height,
 * weight, BMI): it shows ticked and cannot be changed. `detail` is "" when
 * there is none.
 */
final class OnboardingScreeningRow extends CapabilityData
{
    public function __construct(
        public readonly string $item,
        public readonly string $label,
        public readonly string $detail,
        public readonly bool $is_on,
        public readonly bool $is_locked,
    ) {}
}
