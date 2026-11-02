<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-046: the empty state. */
final class MealEmpty extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $note,
    ) {}
}
