<?php

namespace App\Capabilities\Reads\Data\Today;

use App\Capabilities\Support\Tone;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A progress bar: its label, its text and how full it is. The server works out the fraction (CAP-001). */
final class Bar extends CapabilityData
{
    public function __construct(
        #[Field(description: '"Calories"')] public readonly string $label,
        #[Field(description: '"1,850 / 2,400 kcal"')] public readonly string $value_text,
        #[Field(description: '0 to 1.')] public readonly float $fraction,
        #[Field(enum: [Tone::Good->value, Tone::Neutral->value, Tone::Warning->value, Tone::Info->value])] public readonly string $tone,
    ) {}
}
