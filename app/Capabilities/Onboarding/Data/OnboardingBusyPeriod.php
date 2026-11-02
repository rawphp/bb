<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A time the user can't eat (ONB-014), on some ISO weekdays. */
final class OnboardingBusyPeriod extends CapabilityData
{
    /** @param  list<int>  $weekdays */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly OnboardingTime $start,
        public readonly OnboardingTime $end,
        public readonly array $weekdays,
        public readonly string $weekdays_text,
    ) {}
}
