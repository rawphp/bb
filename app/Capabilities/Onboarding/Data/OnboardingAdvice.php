<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** ONB-021: start with Maintenance first. The action answers `goal` with `action_goal`. */
final class OnboardingAdvice extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        public readonly string $action_title,
        public readonly string $action_goal,
    ) {}
}
