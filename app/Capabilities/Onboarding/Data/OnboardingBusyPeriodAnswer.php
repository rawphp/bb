<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A busy period. A null `id` adds a new one. */
final class OnboardingBusyPeriodAnswer extends CapabilityData
{
    /** @param  list<int>  $weekdays  ISO, 1 = Monday */
    public function __construct(
        #[Field(maxLength: 60)]
        public readonly string $label,
        #[Field(minimum: 0, maximum: 1439)]
        public readonly int $start_minutes,
        #[Field(minimum: 0, maximum: 1439)]
        public readonly int $end_minutes,
        #[Field(minItems: 1, maxItems: 7)]
        public readonly array $weekdays,
        public readonly ?string $id = null,
    ) {}
}
