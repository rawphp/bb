<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A ready-made daily step range (ONB-013), such as "8k to 13k". */
final class OnboardingStepPreset extends CapabilityData
{
    public function __construct(
        public readonly int $min,
        public readonly int $max,
        public readonly string $title,
    ) {}
}
