<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An ingredient as the editor saves it. */
final class RecipeIngredientInput extends CapabilityData
{
    public function __construct(
        public readonly string $food_id,
        #[Field(minimum: 0.01)]
        public readonly float $grams,
        public readonly bool $is_cooked,
    ) {}
}
