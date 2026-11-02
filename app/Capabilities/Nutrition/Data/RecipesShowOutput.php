<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** recipes_show: the Recipes screen (RCP-020). */
final class RecipesShowOutput extends CapabilityData
{
    public function __construct(
        /** @var list<RecipeRow> */
        #[Field(items: RecipeRow::class)]
        public readonly array $rows,
        public readonly ?string $empty_text,
    ) {}
}
