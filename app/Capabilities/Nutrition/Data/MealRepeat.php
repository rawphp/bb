<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-061: an earlier meal of the same name, which meal_copy copies into the empty meal. */
final class MealRepeat extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The earlier meal: meal_copy\'s meal_id')]
        public readonly string $meal_id,
        #[Field(description: 'Its day, "Tue 3 Nov"')]
        public readonly string $title,
        #[Field(description: 'The names of its foods and recipes, "Chicken breast · White rice"')]
        public readonly string $foods_text,
    ) {}
}
