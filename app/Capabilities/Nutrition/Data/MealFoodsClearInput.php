<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_foods_clear: the menu's Remove foods (MEAL-009). Recipe servings stay. */
final class MealFoodsClearInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
    ) {}
}
