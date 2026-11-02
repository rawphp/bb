<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 2, about you (ONB-004). */
final class OnboardingAboutYouSection extends CapabilityData
{
    /** @param  list<OnboardingChoice>  $sex_options */
    public function __construct(
        public readonly string $name,
        #[Field(items: OnboardingChoice::class)]
        public readonly array $sex_options,
        public readonly OnboardingDateField $birth_date,
        public readonly OnboardingHeight $height,
        public readonly OnboardingWeight $weight,
    ) {}
}
