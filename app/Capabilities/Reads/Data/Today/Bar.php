<?php

namespace App\Capabilities\Reads\Data\Today;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\Tone;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A progress bar: its label, its text and how full it is. The server works out the fraction (CAP-001). */
final class Bar extends CapabilityData
{
    public function __construct(
        #[Field(description: '"Cal" or "P"')] public readonly string $label,
        #[Field(description: '"780/2600" or "51/170"')] public readonly string $value_text,
        #[Field(description: '0 to 1.')] public readonly float $fraction,
        #[Field(enum: DomainValues::TONES)] public readonly string $tone,
    ) {}

    /** "Cal 2454/2600": a value against its target, filled to the target and no further; a zero target stays empty. */
    public static function of(string $label, float $value, float $target): self
    {
        return new self(
            label: $label,
            value_text: (int) round($value).'/'.(int) round($target),
            fraction: $target > 0 ? round(min(1.0, max(0.0, $value / $target)), 3) : 0.0,
            tone: Tone::Neutral->value,
        );
    }
}
