<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** meal_food_step: + or − on an entry, by the server's step (MEAL-004). */
final class MealFoodStepInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $entry_id,
        #[Field(enum: ['up', 'down'])]
        public readonly string $direction,
    ) {}
}
