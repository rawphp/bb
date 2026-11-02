<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A height in the unit on screen, tagged with it (CAP-015): `cm`, or `feet`
 * and `inches`. A unit with no number only switches the unit.
 */
final class OnboardingHeightAnswer extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['cm', 'feetInches'])]
        public readonly string $unit,
        #[Field(minimum: 0)]
        public readonly ?float $cm = null,
        #[Field(minimum: 0)]
        public readonly ?int $feet = null,
        #[Field(minimum: 0)]
        public readonly ?float $inches = null,
    ) {}
}
