<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_show: one meal. */
final class MealShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
    ) {}
}
