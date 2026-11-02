<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A scanned row as the user left it: grams as shown, or as typed, at from_portion. */
final class ScanAdjustRow extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        #[Field(minimum: 0.1)]
        public readonly float $grams,
        public readonly ?string $food_id = null,
        #[Field(minimum: 0, description: 'The row\'s base_grams as the draft returned it; left out, grams is taken as typed at from_portion')]
        public readonly ?float $base_grams = null,
    ) {}
}
