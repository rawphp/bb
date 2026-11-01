<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A daily step range for one ISO weekday. */
final class OnboardingStepRangeAnswer extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 1, maximum: 7)]
        public readonly int $weekday,
        #[Field(minimum: 0)]
        public readonly int $min,
        #[Field(minimum: 0)]
        public readonly int $max,
    ) {}
}
