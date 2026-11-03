<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\MuscleGroup;

/** One exercise of a template day, as the first mesocycle takes it. */
final readonly class OnboardingTemplateSlot
{
    /** @param  ?int  $exerciseId  the library exercise the first mesocycle's slot saves (ONB-020) */
    public function __construct(
        public string $exerciseName,
        public MuscleGroup $muscle,
        public int $sets,
        public ?int $exerciseId = null,
    ) {}
}
