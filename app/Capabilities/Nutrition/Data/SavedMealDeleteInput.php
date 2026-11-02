<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** saved_meal_delete: delete a saved meal (MEAL-071). */
final class SavedMealDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $saved_meal_id,
    ) {}
}
