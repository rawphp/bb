<?php

namespace App\Capabilities\Nutrition\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** recipe_save: create or edit a recipe; planned copies not checked in follow (RCP-001, RCP-021). */
final class RecipeSaveInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The recipe\'s ULID: new for a create (CAP-019)')]
        public readonly string $id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $name,
        #[Field(minimum: 0.1, maximum: 100)]
        public readonly float $servings,
        #[Field(enum: ['breakfast', 'lunch', 'dinner', 'snack'])]
        public readonly string $meal_type,
        /** @var list<RecipeIngredientInput> */
        #[Field(items: RecipeIngredientInput::class, minItems: 1, maxItems: 100)]
        public readonly array $ingredients,
        /** @var list<string> */
        #[Field(maxItems: 50)]
        public readonly array $steps = [],
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
            'steps.*' => ['filled', Rule::string()->max(1000)],
        ];
    }
}
