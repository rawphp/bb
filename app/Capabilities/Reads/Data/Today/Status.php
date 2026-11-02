<?php

namespace App\Capabilities\Reads\Data\Today;

use App\Capabilities\Support\Tone;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A pill, line or note that arrives decided: its text and its tone (CAP-018, UI-004). */
final class Status extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(enum: [Tone::Good->value, Tone::Neutral->value, Tone::Warning->value, Tone::Info->value])] public readonly string $tone,
    ) {}
}
