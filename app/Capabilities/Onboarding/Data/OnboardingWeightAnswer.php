<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A weight in the unit on screen, tagged with it (CAP-015). A unit with no value only switches the unit. */
final class OnboardingWeightAnswer extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $unit,
        #[Field(minimum: 0)]
        public readonly ?float $value = null,
    ) {}
}
