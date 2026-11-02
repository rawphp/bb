<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 13, calories (ONB-017). `options` are the recommendation and "Choose my
 * own"; `own_kcal` is the user's daily average while they choose their own.
 * `week` is the selected week in plan-week order, empty while there is none.
 */
final class OnboardingCaloriesSection extends CapabilityData
{
    /**
     * @param  list<OnboardingChoice>  $options
     * @param  list<OnboardingCalorieDay>  $week
     */
    public function __construct(
        #[Field(items: OnboardingChoice::class)]
        public readonly array $options,
        public readonly ?int $own_kcal,
        public readonly int $own_kcal_max,
        public readonly string $own_unit,
        public readonly string $week_note,
        #[Field(items: OnboardingCalorieDay::class)]
        public readonly array $week,
    ) {}
}
