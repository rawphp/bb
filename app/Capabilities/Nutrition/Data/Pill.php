<?php

namespace App\Capabilities\Nutrition\Data;

use App\Capabilities\Support\Tone;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A status pill: its text and the tone the server picked (CAP-018, UI-004). */
final class Pill extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(enum: [Tone::Good->value, Tone::Neutral->value, Tone::Warning->value, Tone::Info->value])]
        public readonly string $tone,
    ) {}
}
