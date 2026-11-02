<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A food in a Pantry list (FOOD-002, FOOD-006, FOOD-009, FOOD-022, FOOD-028). Raw and cooked nutrition both come, so the Raw/Cooked toggle swaps text and computes nothing. */
final class FoodRow extends CapabilityData
{
    public function __construct(
        public readonly string $food_id,
        public readonly string $name,
        #[Field(description: 'The brand or restaurant')]
        public readonly ?string $detail,
        public readonly Macros $raw,
        #[Field(description: 'Null when the food has no cooked value, so it has no Raw/Cooked toggle')]
        public readonly ?Macros $cooked,
        #[Field(description: 'per 100 g, or per 3.5 oz')]
        public readonly string $basis_text,
        public readonly bool $is_curated,
        public readonly bool $is_favorite,
        public readonly bool $is_in_meal,
        #[Field(description: 'The credit the food\'s dataset asks for, from Food::attribution(); shown in the food detail (ODbL for Open Food Facts). Null for curated and custom foods')]
        public readonly ?string $attribution,
    ) {}
}
