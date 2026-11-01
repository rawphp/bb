<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Any answers from steps 1 to 13. A key left out, or null, keeps the saved
 * answer. Choice answers send back a choice's `value` from `onboarding_show`.
 * A list replaces the saved list, except `screening`, which sets each item it
 * names.
 */
final class OnboardingAnswers extends CapabilityData
{
    /**
     * @param  list<OnboardingScreeningAnswer>|null  $screening
     * @param  list<OnboardingTrainingDayAnswer>|null  $training_days
     * @param  list<OnboardingStepRangeAnswer>|null  $step_ranges
     * @param  list<OnboardingBusyPeriodAnswer>|null  $busy_periods
     * @param  list<OnboardingWeighInAnswer>|null  $weigh_ins
     */
    public function __construct(
        // Step 1 (ONB-003, ONB-021)
        #[Field(enum: ['fatLoss', 'muscleGain', 'maintenance'])]
        public readonly ?string $goal = null,
        public readonly ?bool $finished_diet_recently = null,
        // Step 2 (ONB-004)
        #[Field(maxLength: 100)]
        public readonly ?string $name = null,
        #[Field(enum: ['male', 'female'])]
        public readonly ?string $sex = null,
        #[Field(format: 'date')]
        public readonly ?string $birth_date = null,
        public readonly ?OnboardingHeightAnswer $height = null,
        public readonly ?OnboardingWeightAnswer $weight = null,
        // Step 3 (SAFE-001)
        #[Field(items: OnboardingScreeningAnswer::class)]
        public readonly ?array $screening = null,
        // Step 4 (ONB-006)
        public readonly ?bool $uses_glp1 = null,
        // Step 5 (ONB-007)
        #[Field(enum: ['lowest', 'low', 'high', 'highest'])]
        public readonly ?string $body_fat = null,
        // Step 6 (ONB-008): the rate is as the slider shows it, positive for fat loss.
        public readonly ?string $rate_option = null,
        public readonly ?float $custom_rate = null,
        #[Field(minimum: 1)]
        public readonly ?int $weeks = null,
        #[Field(format: 'date')]
        public readonly ?string $start_date = null,
        #[Field(format: 'date')]
        public readonly ?string $last_day = null,
        public readonly ?OnboardingWeightAnswer $target_weight = null,
        // Step 7 (ONB-010)
        #[Field(enum: ['beginner', 'intermediate', 'advanced'])]
        public readonly ?string $experience = null,
        // Step 8 (ONB-011)
        #[Field(items: OnboardingTrainingDayAnswer::class)]
        public readonly ?array $training_days = null,
        // Step 9 (ONB-012)
        public readonly ?string $training_plan = null,
        // Step 10 (ONB-013)
        #[Field(items: OnboardingStepRangeAnswer::class)]
        public readonly ?array $step_ranges = null,
        // Step 11 (ONB-014)
        #[Field(minimum: 0, maximum: 1439)]
        public readonly ?int $first_meal_minutes = null,
        #[Field(minimum: 0, maximum: 1439)]
        public readonly ?int $last_meal_minutes = null,
        #[Field(minimum: 2, maximum: 6)]
        public readonly ?int $meal_count = null,
        #[Field(items: OnboardingBusyPeriodAnswer::class)]
        public readonly ?array $busy_periods = null,
        // Step 12 (ONB-015, ONB-016)
        #[Field(items: OnboardingWeighInAnswer::class)]
        public readonly ?array $weigh_ins = null,
        // An ISO weekday as text: the value of one of the show's review_days.
        #[Field(enum: ['1', '2', '3', '4', '5', '6', '7'])]
        public readonly ?string $review_weekday = null,
        // Step 13 (ONB-017)
        #[Field(enum: ['recommendation', 'own'])]
        public readonly ?string $calories = null,
        #[Field(minimum: 0, maximum: 10000)]
        public readonly ?int $own_kcal = null,
    ) {}
}
