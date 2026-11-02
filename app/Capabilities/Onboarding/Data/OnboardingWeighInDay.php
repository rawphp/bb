<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** One ISO weekday on step 12, with its reminder time when it is on. */
final class OnboardingWeighInDay extends CapabilityData
{
    public function __construct(
        public readonly int $weekday,
        public readonly string $name,
        public readonly string $full_name,
        public readonly bool $is_on,
        public readonly OnboardingTime $time,
    ) {}
}
