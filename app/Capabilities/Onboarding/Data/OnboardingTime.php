<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A time of day: minutes after midnight for the picker, and its text ("6:00 PM"). */
final class OnboardingTime extends CapabilityData
{
    public function __construct(
        public readonly int $minutes,
        public readonly string $text,
    ) {}
}
