<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 8, training days (ONB-011): all seven weekdays, Monday first, and the length stepper's bounds. */
final class OnboardingTrainingDaysSection extends CapabilityData
{
    /** @param  list<OnboardingTrainingDay>  $days */
    public function __construct(
        public readonly string $help,
        #[Field(items: OnboardingTrainingDay::class)]
        public readonly array $days,
        public readonly int $length_min,
        public readonly int $length_max,
        public readonly int $length_step,
    ) {}
}
