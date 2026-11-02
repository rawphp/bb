<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** An ingredient of a recipe (RCP-001, FOOD-006). */
final class RecipeIngredient extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $food_id,
        public readonly string $name,
        public readonly float $grams,
        public readonly string $amount_text,
        public readonly bool $is_cooked,
        public readonly bool $can_toggle_cooked,
    ) {}
}
