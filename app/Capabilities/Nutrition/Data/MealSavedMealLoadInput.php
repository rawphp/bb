<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_saved_meal_load: a saved meal's foods join the meal; locked ones keep their amounts (MEAL-070). */
final class MealSavedMealLoadInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $saved_meal_id,
    ) {}
}
