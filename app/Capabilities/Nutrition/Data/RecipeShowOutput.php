<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** recipe_show: the recipe screen and editor (RCP-001, RCP-020). */
final class RecipeShowOutput extends CapabilityData
{
    public function __construct(
        public readonly string $recipe_id,
        public readonly string $name,
        #[Field(description: 'A library recipe')]
        public readonly bool $is_read_only,
        public readonly bool $can_delete,
        public readonly float $servings,
        public readonly string $servings_text,
        #[Field(description: 'One of: breakfast, lunch, dinner, snack; or null')]
        public readonly ?string $meal_type,
        public readonly ?string $meal_type_title,
        #[Field(description: '330 kcal · P 28 g · F 3 g · C 47 g')]
        public readonly string $per_serving_text,
        /** @var list<RecipeIngredient> */
        #[Field(items: RecipeIngredient::class)]
        public readonly array $ingredients,
        /** @var list<string> */
        public readonly array $steps,
    ) {}
}
