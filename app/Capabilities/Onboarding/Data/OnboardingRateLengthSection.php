<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 6, rate and length (ONB-008, ONB-009, TBL-010). Changing the start,
 * the last day, the length or the target is an answer; the server works out
 * the others. `custom_rate` reads as a positive "% of body weight per week"
 * for fat loss. `recommended_text` is "" when the goal has none.
 */
final class OnboardingRateLengthSection extends CapabilityData
{
    /** @param  list<OnboardingChoice>  $options  presets, then Custom */
    public function __construct(
        #[Field(items: OnboardingChoice::class)]
        public readonly array $options,
        public readonly bool $is_custom,
        public readonly OnboardingSlider $custom_rate,
        public readonly OnboardingSlider $weeks,
        public readonly string $recommended_text,
        public readonly OnboardingDateField $start_date,
        public readonly OnboardingDateField $last_day,
        public readonly OnboardingTargetWeight $target_weight,
    ) {}
}
