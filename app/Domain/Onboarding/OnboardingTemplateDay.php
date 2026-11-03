<?php

namespace App\Domain\Onboarding;

/** One day of a template: its label and its slots in order. */
final readonly class OnboardingTemplateDay
{
    /** @param  list<OnboardingTemplateSlot>  $slots */
    public function __construct(
        public string $label,
        public array $slots,
    ) {}
}
