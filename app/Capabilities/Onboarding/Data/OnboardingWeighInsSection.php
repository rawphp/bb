<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 12, weigh-ins and the review day (ONB-015, ONB-016). `hint` is "" when
 * there are enough weigh-in days. `review_days` are the seven weekdays, with
 * the review day selected; their values are ISO weekdays as text.
 */
final class OnboardingWeighInsSection extends CapabilityData
{
    /**
     * @param  list<OnboardingWeighInDay>  $days  Monday first
     * @param  list<OnboardingChoice>  $review_days
     */
    public function __construct(
        #[Field(items: OnboardingWeighInDay::class)]
        public readonly array $days,
        public readonly string $time_note,
        public readonly string $hint,
        public readonly string $review_note,
        #[Field(items: OnboardingChoice::class)]
        public readonly array $review_days,
    ) {}
}
