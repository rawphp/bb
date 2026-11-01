<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 9, training plan (ONB-012): the templates that fit, recommended first,
 * then the Meso Builder and "I'll build it later". `note` is "" when there is
 * none.
 */
final class OnboardingTrainingPlanSection extends CapabilityData
{
    /** @param  list<OnboardingChoice>  $options */
    public function __construct(
        public readonly string $note,
        #[Field(items: OnboardingChoice::class)]
        public readonly array $options,
    ) {}
}
