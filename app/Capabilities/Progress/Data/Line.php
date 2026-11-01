<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A label and a display-ready value with its unit, for example "Start" and "83.2 kg" (CAP-015). */
final class Line extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        #[Field(description: 'The value\'s colour; neutral for plain text.', enum: ['good', 'neutral', 'warning', 'info'])]
        public readonly string $tone,
    ) {}
}
