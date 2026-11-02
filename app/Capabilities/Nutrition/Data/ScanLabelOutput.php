<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** FOOD-042: a nutrition label read into the custom food form, for the user to confirm. */
final class ScanLabelOutput extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $barcode,
        public readonly float $serving_size,
        #[Field(enum: ['g', 'cup', 'tbsp', 'tsp', 'piece'])]
        public readonly string $serving_unit,
        public readonly float $serving_grams,
        public readonly float $protein,
        public readonly float $fat,
        public readonly float $carbs,
        public readonly float $fiber,
        public readonly string $kcal_text,
    ) {}
}
