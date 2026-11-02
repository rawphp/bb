<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_food_remove: swipe a food or serving away (MEAL-008). */
final class MealFoodRemoveInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $entry_id,
    ) {}
}
