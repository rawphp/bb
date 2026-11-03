<?php

namespace App\Capabilities\Onboarding\Data;

use App\Capabilities\Support\Choice;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 7, training experience (ONB-010, TBL-020). */
final class OnboardingExperienceSection extends CapabilityData
{
    /** @param  list<Choice>  $options  values are experience levels */
    public function __construct(
        #[Field(items: Choice::class)]
        public readonly array $options,
    ) {}
}
