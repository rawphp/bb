<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One SAFE-001 item. The age, height, weight and BMI rows are always locked:
 * they are worked out from step 2, show ticked when they apply, and
 * `onboarding_answer` cannot send them. `detail` is "" when there is none.
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
