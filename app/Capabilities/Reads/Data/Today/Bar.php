<?php

namespace App\Capabilities\Reads\Data\Today;

use App\Capabilities\Support\DomainValues;
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
}
