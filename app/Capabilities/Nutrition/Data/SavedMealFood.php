<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A food and its grams, locked, as a scanned meal adds or saves it (FOOD-045, FOOD-046). */
final class SavedMealFood extends CapabilityData
{
    public function __construct(
        public readonly string $food_id,
        #[Field(minimum: 0.1)]
        public readonly float $grams,
    ) {}
}
