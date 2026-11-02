<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** food_barcode_lookup: the product, or what to do when it is not known (FOOD-041). */
final class FoodBarcodeLookupOutput extends CapabilityData
{
    public function __construct(
        public readonly string $barcode,
        #[Field(enum: ['found', 'not_found'])]
        public readonly string $status,
        public readonly ?FoodRow $food,
        public readonly ?string $message,
    ) {}
}
