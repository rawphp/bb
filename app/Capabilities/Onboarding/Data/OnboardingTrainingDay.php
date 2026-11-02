<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** One ISO weekday (1 = Monday) on step 8, with its start and length when it is on. */
final class OnboardingTrainingDay extends CapabilityData
{
    public function __construct(
        public readonly int $weekday,
        public readonly string $name,
        public readonly string $full_name,
        public readonly bool $is_on,
        public readonly OnboardingTime $start,
        public readonly int $length_minutes,
        public readonly string $length_text,
    ) {}
}
