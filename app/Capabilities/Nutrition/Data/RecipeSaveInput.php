<?php

namespace App\Capabilities\Nutrition\Data;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
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
            // Implicit: Laravel skips a plain rule for a value that is only spaces.
            'name' => [new class implements ValidationRule
            {
                public bool $implicit = true;

                public function validate(string $attribute, mixed $value, Closure $fail): void
                {
                    if (is_string($value) && trim($value) === '') {
                        $fail('Give the recipe a name.');
                    }
                }
            }],
            'steps.*' => ['filled', Rule::string()->max(1000)],
        ];
    }
}
