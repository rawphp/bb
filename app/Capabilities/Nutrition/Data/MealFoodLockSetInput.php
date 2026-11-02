<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_food_lock_set: lock or unlock an entry's amount (MEAL-005). */
final class MealFoodLockSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $entry_id,
        public readonly bool $locked,
    ) {}
}
