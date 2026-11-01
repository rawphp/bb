<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A weigh-in weekday (ISO). A null time keeps the saved one, or the default for a new day. */
final class OnboardingWeighInAnswer extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 1, maximum: 7)]
        public readonly int $weekday,
        #[Field(minimum: 0, maximum: 1439)]
        public readonly ?int $minutes = null,
    ) {}
}
