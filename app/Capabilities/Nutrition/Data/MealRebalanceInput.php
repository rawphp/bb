<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_rebalance: fit every unlocked entry to the meal's targets (MEAL-006). */
final class MealRebalanceInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
    ) {}
}
