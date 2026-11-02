<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\MuscleGroup;

/** One exercise of a template day, as the first mesocycle takes it. */
final readonly class OnboardingTemplateSlot
{
    public function __construct(
        public string $exerciseName,
        public MuscleGroup $muscle,
        public int $sets,
    ) {}
}
