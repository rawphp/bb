<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_delete: the menu's Delete meal (MEAL-009). */
final class MealDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
    ) {}
}
