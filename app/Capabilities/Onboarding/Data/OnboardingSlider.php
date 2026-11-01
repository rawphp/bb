<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A slider or stepper: its value, bounds and increment, and the value as text with its unit. */
final class OnboardingSlider extends CapabilityData
{
    public function __construct(
        public readonly float $value,
        public readonly float $min,
        public readonly float $max,
        public readonly float $step,
        public readonly string $text,
    ) {}
}
