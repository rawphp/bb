<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The body-fat bucket, its label, and the choices by sex. */
final class BodyFat extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['lowest', 'low', 'high', 'highest'])]
        public readonly string $value,
        public readonly string $text,
        public readonly BodyFatChoices $choices,
    ) {}
}
