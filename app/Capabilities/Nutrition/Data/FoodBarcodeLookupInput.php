<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** food_barcode_lookup: a scanned or typed barcode (FOOD-041). */
final class FoodBarcodeLookupInput extends CapabilityData
{
    public function __construct(
        #[Field(description: '1 to 14 digits')]
        public readonly string $barcode,
    ) {}
}
