<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 10, daily steps (ONB-013, SAFE-015). The stepper runs from 0 to `max_steps` by `increment`. */
final class OnboardingStepsSection extends CapabilityData
{
    /**
     * @param  list<OnboardingStepPreset>  $presets
     * @param  list<OnboardingStepGroup>  $groups
     * @param  list<OnboardingStepDay>  $days  Monday first
     */
    public function __construct(
        public readonly string $note,
        #[Field(items: OnboardingStepPreset::class)]
        public readonly array $presets,
        #[Field(items: OnboardingStepGroup::class)]
        public readonly array $groups,
        #[Field(items: OnboardingStepDay::class)]
        public readonly array $days,
        public readonly int $increment,
        public readonly int $max_steps,
    ) {}
}
