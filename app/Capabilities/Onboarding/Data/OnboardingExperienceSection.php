<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 7, training experience (ONB-010, TBL-020). */
final class OnboardingExperienceSection extends CapabilityData
{
    /** @param  list<OnboardingChoice>  $options  values are experience levels */
    public function __construct(
        #[Field(items: OnboardingChoice::class)]
        public readonly array $options,
    ) {}
}
