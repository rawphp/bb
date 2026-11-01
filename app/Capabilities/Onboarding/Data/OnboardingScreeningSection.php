<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 3, health screening (SAFE-001, SAFE-002, SAFE-014). */
final class OnboardingScreeningSection extends CapabilityData
{
    /** @param  list<OnboardingScreeningRow>  $rows  in SAFE-001's order */
    public function __construct(
        public readonly string $prompt,
        public readonly OnboardingStatus $clearance_note,
        public readonly OnboardingStatus $medical_diets_note,
        #[Field(items: OnboardingScreeningRow::class)]
        public readonly array $rows,
    ) {}
}
