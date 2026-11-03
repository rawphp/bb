<?php

namespace App\Capabilities\Nutrition\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One of the meal's four bars (MEAL-001): planned against target, as shown ("550/1040"), and how full to draw it. */
final class MacroBar extends CapabilityData
{
    public function __construct(
        #[Field(description: 'Cal, P, F or C')]
        public readonly string $label,
        #[Field(description: '550/1040')]
        public readonly string $value_text,
        #[Field(minimum: 0, maximum: 1)]
        public readonly float $fraction,
        #[Field(enum: DomainValues::TONES)]
        public readonly string $tone,
    ) {}
}
