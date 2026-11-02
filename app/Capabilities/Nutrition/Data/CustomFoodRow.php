<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A custom food in the manager (FOOD-061), with the values the edit form opens with. */
final class CustomFoodRow extends CapabilityData
{
    public function __construct(
        public readonly string $food_id,
        public readonly string $name,
        #[Field(description: '1 cup · 120 kcal')]
        public readonly string $detail,
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
    ) {}
}
