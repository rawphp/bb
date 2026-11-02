<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A saved meal in a list (MEAL-071). */
final class SavedMealRow extends CapabilityData
{
    public function __construct(
        public readonly string $saved_meal_id,
        public readonly string $name,
        #[Field(description: '3 foods')]
        public readonly string $detail,
    ) {}
}
