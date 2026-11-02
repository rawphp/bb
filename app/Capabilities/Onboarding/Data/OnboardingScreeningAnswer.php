<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Ticks or unticks one SAFE-001 item. The measured items (age, height, weight,
 * BMI) come from step 2 and cannot be sent.
 */
final class OnboardingScreeningAnswer extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['pregnantOrBreastfeeding', 'tryingToConceive', 'transgenderOnHormoneTherapy', 'heartThyroidOrPCOS', 'weightLossSurgery', 'gallbladderDisease', 'autoimmuneDisease', 'diabetes', 'eatingDisorderHistory'])]
        public readonly string $item,
        public readonly bool $is_on,
    ) {}
}
