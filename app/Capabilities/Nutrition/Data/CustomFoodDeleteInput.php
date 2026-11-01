<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** custom_food_delete: delete a custom food (FOOD-061). */
final class CustomFoodDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $food_id,
    ) {}
}
