<?php

namespace App\Capabilities\Onboarding\Data;

use App\Capabilities\Support\Choice;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Height in the unit on screen (CAP-015): `cm`, or `feet` and `inches`. The
 * numbers are null until the height is set, and `text` is then "".
 */
final class OnboardingHeight extends CapabilityData
{
    /** @param  list<Choice>  $unit_options */
    public function __construct(
        #[Field(enum: ['cm', 'feetInches'])]
        public readonly string $unit,
        #[Field(items: Choice::class)]
        public readonly array $unit_options,
        public readonly ?float $cm,
        public readonly ?int $feet,
        public readonly ?float $inches,
        public readonly string $text,
    ) {}
}
