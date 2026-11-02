<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Whether one screening item applies. */
final class ScreeningAnswerInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['ageOutOfRange', 'heightOutOfRange', 'weightOverLimit', 'bmiUnderLimit', 'pregnantOrBreastfeeding', 'tryingToConceive', 'transgenderOnHormoneTherapy', 'heartThyroidOrPCOS', 'weightLossSurgery', 'gallbladderDisease', 'autoimmuneDisease', 'diabetes', 'eatingDisorderHistory'])]
        public readonly string $item,
        public readonly bool $applies,
    ) {}
}
