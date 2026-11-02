<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A date picker. Dates are "yyyy-mm-dd". `date` is null until it is set, and
 * the picker then opens on `initial`. `text` is "" while `date` is null.
 */
final class OnboardingDateField extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly ?string $date,
        public readonly string $text,
        #[Field(format: 'date')]
        public readonly string $initial,
        #[Field(format: 'date')]
        public readonly ?string $min,
        #[Field(format: 'date')]
        public readonly ?string $max,
        public readonly bool $is_locked,
    ) {}
}
