<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** recipe_generate: draft a recipe with AI; the profile's diet filters apply (RCP-010). */
final class RecipeGenerateInput extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 0)]
        public readonly float $protein,
        #[Field(minimum: 0)]
        public readonly float $fat,
        #[Field(minimum: 0)]
        public readonly float $carbs,
        #[Field(enum: ['breakfast', 'lunch', 'dinner', 'snack'])]
        public readonly string $meal_type,
        #[Field(enum: ['simple', 'moderate', 'elaborate'])]
        public readonly string $complexity,
        #[Field(minimum: 0)]
        public readonly ?float $kcal = null,
        /** @var list<string> */
        #[Field(maxItems: 20)]
        public readonly array $ingredients = [],
        #[Field(maxLength: 500)]
        public readonly ?string $preferences = null,
    ) {}
}
