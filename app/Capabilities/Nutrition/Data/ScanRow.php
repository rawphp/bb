<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** FOOD-043: a food of the scanned meal at the current portion. */
final class ScanRow extends CapabilityData
{
    public function __construct(
        #[Field(description: 'What the AI called the food')]
        public readonly string $name,
        #[Field(description: 'At the current portion; sent back unchanged to scan_adjust')]
        public readonly float $grams,
        #[Field(description: 'The grams at 1×, unrounded; sent back unchanged to scan_adjust so a portion step rounds once (FOOD-045)')]
        public readonly float $base_grams,
        public readonly string $grams_text,
        public readonly ?string $food_id,
        #[Field(description: 'The matched food, or No match')]
        public readonly string $match_text,
        public readonly ?string $detail,
        public readonly ?string $kcal_text,
    ) {}
}
