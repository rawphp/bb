<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_check_in_undo: remove the meal's check-in (MEAL-044). */
final class MealCheckInUndoInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
    ) {}
}
