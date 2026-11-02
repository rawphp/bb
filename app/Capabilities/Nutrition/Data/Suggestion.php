<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** FOOD-024: the corrected spelling, and the prompt that offers it. */
final class Suggestion extends CapabilityData
{
    public function __construct(
        #[Field(description: 'Did you mean chicken breast?')]
        public readonly string $text,
        public readonly string $query,
    ) {}
}
