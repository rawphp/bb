<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A scanned row as the user left it. */
final class ScanAdjustRow extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        #[Field(minimum: 0.1)]
        public readonly float $grams,
        public readonly ?string $food_id = null,
    ) {}
}
