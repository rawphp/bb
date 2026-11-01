<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 11, meals (ONB-014): one eating window for every day, the meal count and busy periods. */
final class OnboardingMealsSection extends CapabilityData
{
    /** @param  list<OnboardingBusyPeriod>  $busy_periods */
    public function __construct(
        public readonly OnboardingTime $first_meal,
        public readonly OnboardingTime $last_meal,
        public readonly string $window_note,
        public readonly OnboardingSlider $meal_count,
        public readonly string $busy_note,
        #[Field(items: OnboardingBusyPeriod::class)]
        public readonly array $busy_periods,
    ) {}
}
