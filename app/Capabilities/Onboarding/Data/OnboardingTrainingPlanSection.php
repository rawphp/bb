<?php

namespace App\Capabilities\Onboarding\Data;

use App\Capabilities\Support\Choice;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 9, training plan (ONB-012): the templates that fit, recommended first,
 * then the Meso Builder and "I'll build it later". `note` is "" when there is
 * none.
 */
final class OnboardingTrainingPlanSection extends CapabilityData
{
    /** @param  list<Choice>  $options */
    public function __construct(
        public readonly string $note,
        #[Field(items: Choice::class)]
        public readonly array $options,
    ) {}
}
