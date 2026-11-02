<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A label and a display-ready value with its unit, for example "Start" and "83.2 kg" (CAP-015). */
final class Line extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        #[Field(description: 'The value\'s colour; neutral for plain text.', enum: [Tone::Good->value, Tone::Neutral->value, Tone::Warning->value, Tone::Info->value])]
        public readonly string $tone,
    ) {}
}
