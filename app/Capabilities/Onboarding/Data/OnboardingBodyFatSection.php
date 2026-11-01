<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 5, body fat (ONB-007, TBL-001): the photo buckets for `sex`, leanest first. */
final class OnboardingBodyFatSection extends CapabilityData
{
    /** @param  list<OnboardingChoice>  $options  values are body-fat buckets */
    public function __construct(
        public readonly string $help,
        #[Field(enum: ['male', 'female'])]
        public readonly string $sex,
        #[Field(items: OnboardingChoice::class)]
        public readonly array $options,
    ) {}
}
