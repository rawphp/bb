<?php

namespace App\Domain\Onboarding;

/** A template on step 9 (ONB-012). `isRecommended`: the kind the app recommends for the user's goal. */
final readonly class TemplateOption
{
    public function __construct(
        public OnboardingTemplate $template,
        public bool $isRecommended,
    ) {}

    /** "3 days a week · Full body". */
    public function summary(): string
    {
        return "{$this->template->daysPerWeek} days a week · ".OnboardingTraining::splitTitle($this->template->split);
    }
}
