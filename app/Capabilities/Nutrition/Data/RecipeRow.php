<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A recipe in a list: its meal type and one serving's macros (RCP-020). */
final class RecipeRow extends CapabilityData
{
    public function __construct(
        public readonly string $recipe_id,
        public readonly string $name,
        public readonly ?string $meal_type_title,
        #[Field(description: '330 kcal · P 28 · F 3 · C 47, per serving')]
        public readonly string $macros_text,
        public readonly bool $is_library,
    ) {}
}
