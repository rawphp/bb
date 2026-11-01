<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** One ISO weekday's step range on step 10. */
final class OnboardingStepDay extends CapabilityData
{
    public function __construct(
        public readonly int $weekday,
        public readonly string $full_name,
        public readonly int $min,
        public readonly int $max,
        public readonly string $text,
    ) {}
}
