<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** One day of step 13's week split, such as "Mon" and "2,310 kcal". */
final class OnboardingCalorieDay extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly string $kcal_text,
    ) {}
}
