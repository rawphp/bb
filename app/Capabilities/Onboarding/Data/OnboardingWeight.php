<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Weight in the unit on screen (CAP-015). `value` is null until it is set, and `text` is then "". */
final class OnboardingWeight extends CapabilityData
{
    /** @param  list<OnboardingChoice>  $unit_options */
    public function __construct(
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $unit,
        #[Field(items: OnboardingChoice::class)]
        public readonly array $unit_options,
        public readonly ?float $value,
        public readonly string $text,
    ) {}
}
