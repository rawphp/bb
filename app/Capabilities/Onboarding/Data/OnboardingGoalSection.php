<?php

namespace App\Capabilities\Onboarding\Data;

use App\Capabilities\Support\Choice;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 1, goal (ONB-003, ONB-005, ONB-021). */
final class OnboardingGoalSection extends CapabilityData
{
    /** @param  list<Choice>  $options  values are phase types */
    public function __construct(
        public readonly string $help,
        #[Field(items: Choice::class)]
        public readonly array $options,
        public readonly OnboardingQuestion $recent_diet,
        public readonly ?OnboardingAdvice $advice,
    ) {}
}
