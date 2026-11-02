<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** meal_food_amount_set: a typed amount; the other unlocked entries rebalance (MEAL-004, RCP-002). */
final class MealFoodAmountSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $entry_id,
        #[Field(minimum: 0)]
        public readonly float $value,
        #[Field(enum: ['g', 'oz', 'household', 'servings'])]
        public readonly string $unit,
    ) {}
}
