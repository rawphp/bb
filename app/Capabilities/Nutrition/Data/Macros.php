<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** Calories and macros as shown: "355 kcal", "P 68", "F 6", "C 0" (CAP-015). */
final class Macros extends CapabilityData
{
    public function __construct(
        public readonly string $kcal,
        public readonly string $protein,
        public readonly string $fat,
        public readonly string $carbs,
    ) {}
}
