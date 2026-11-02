<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A meal on the Nutrition tab (NAV-001): name, time, and planned against target calories. */
final class NutritionMeal extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $name,
        public readonly ?string $time_text,
        #[Field(description: '550 / 1040 kcal')]
        public readonly string $kcal_text,
        public readonly MealCheckIn $check_in,
    ) {}
}
