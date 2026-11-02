<?php

namespace App\Domain\Onboarding;

use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;

/**
 * A library template (MESO-010) as step 9 and the first mesocycle read it. The caller loads the templates and passes
 * them in; `OnboardingDraft::templates()` loads them from the library.
 */
final readonly class OnboardingTemplate
{
    /**
     * @param  list<PhaseFit>  $phaseFit
     * @param  array<string, string>  $priorities  MuscleGroup value to Priority value (MESO-012)
     * @param  list<OnboardingTemplateDay>  $days  in order
     */
    public function __construct(
        public int $id,
        public string $name,
        public Split $split,
        public int $daysPerWeek,
        public SexFit $sex,
        public array $phaseFit,
        public array $priorities,
        public array $days,
    ) {}
}
