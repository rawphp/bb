<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** recipe_delete: delete the user's own recipe; planned copies stay (RCP-021). */
final class RecipeDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $recipe_id,
    ) {}
}
