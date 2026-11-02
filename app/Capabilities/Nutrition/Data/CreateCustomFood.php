<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** FOOD-062: the prompt under a search, and the name the form opens with. */
final class CreateCustomFood extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $name,
    ) {}
}
