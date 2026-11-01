<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A height in the unit it was typed in (CAP-015): cm, or feet and inches. */
final class HeightInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['cm', 'feetInches'])]
        public readonly string $unit,
        public readonly ?float $cm,
        public readonly ?int $feet,
        public readonly ?float $inches,
    ) {}
}
