<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A food already in the meal the Pantry adds to (FOOD-028). */
final class InMealFood extends CapabilityData
{
    public function __construct(
        public readonly string $food_id,
        public readonly string $name,
    ) {}
}
