<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** recipe_show: one recipe. */
final class RecipeShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $recipe_id,
    ) {}
}
