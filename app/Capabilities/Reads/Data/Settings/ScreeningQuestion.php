<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A screening item (SAFE-001) and whether it applies now. */
final class ScreeningQuestion extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['ageOutOfRange', 'heightOutOfRange', 'weightOverLimit', 'bmiUnderLimit', 'pregnantOrBreastfeeding', 'tryingToConceive', 'transgenderOnHormoneTherapy', 'heartThyroidOrPCOS', 'weightLossSurgery', 'gallbladderDisease', 'autoimmuneDisease', 'diabetes', 'eatingDisorderHistory'])]
        public readonly string $item,
        public readonly string $label,
        public readonly bool $applies,
        #[Field(description: 'worked out from the profile, so it cannot be ticked')]
        public readonly bool $measured,
    ) {}
}
