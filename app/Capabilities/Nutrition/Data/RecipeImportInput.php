<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** recipe_import: draft a recipe from a web page (RCP-003). */
final class RecipeImportInput extends CapabilityData
{
    public function __construct(
        #[Field(maxLength: 2048, format: 'uri')]
        public readonly string $url,
    ) {}
}
