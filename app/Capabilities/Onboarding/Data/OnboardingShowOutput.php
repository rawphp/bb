<?php

namespace App\Capabilities\Onboarding\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Setup as saved on the server (ONB-002): the current step and every step's
 * answers, ready to show. The stepper and the agent read the same payload
 * (AGT-033). `notices` are the callouts for the current step, in order.
 */
final class OnboardingShowOutput extends CapabilityData
{
    /** @param  list<Status>  $notices */
    public function __construct(
        public readonly OnboardingStep $step,
        public readonly bool $training_only,
        #[Field(items: Status::class)]
        public readonly array $notices,
        public readonly OnboardingGoalSection $goal,
        public readonly OnboardingAboutYouSection $about_you,
        public readonly OnboardingScreeningSection $screening,
        public readonly OnboardingQuestion $glp1,
        public readonly OnboardingBodyFatSection $body_fat,
        public readonly OnboardingRateLengthSection $rate_length,
        public readonly OnboardingExperienceSection $experience,
        public readonly OnboardingTrainingDaysSection $training_days,
        public readonly OnboardingTrainingPlanSection $training_plan,
        public readonly OnboardingStepsSection $steps,
        public readonly OnboardingMealsSection $meals,
        public readonly OnboardingWeighInsSection $weigh_ins,
        public readonly OnboardingCaloriesSection $calories,
        public readonly OnboardingReviewSection $review,
    ) {}
}
