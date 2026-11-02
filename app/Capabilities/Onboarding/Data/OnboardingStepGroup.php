<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Weekdays or weekends on step 10. When its days differ, `is_mixed` is true,
 * `min` and `max` are 0 and `text` says so. A write sets the range on each
 * of `weekdays`.
 */
final class OnboardingStepGroup extends CapabilityData
{
    /** @param  list<int>  $weekdays  ISO, 1 = Monday */
    public function __construct(
        #[Field(enum: ['weekdays', 'weekends'])]
        public readonly string $key,
        public readonly string $title,
        public readonly array $weekdays,
        public readonly bool $is_mixed,
        public readonly int $min,
        public readonly int $max,
        public readonly string $text,
    ) {}
}
