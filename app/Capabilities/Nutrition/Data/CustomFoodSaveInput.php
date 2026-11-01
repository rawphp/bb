<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** custom_food_save: create or edit a custom food; nutrition is for one serving (FOOD-060). */
final class CustomFoodSaveInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The food\'s ULID: new for a create (CAP-019)')]
        public readonly string $id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $name,
        #[Field(minimum: 0.01, maximum: 99999)]
        public readonly float $serving_size,
        #[Field(enum: ['g', 'cup', 'tbsp', 'tsp', 'piece'])]
        public readonly string $serving_unit,
        #[Field(minimum: 0.01, maximum: 10000)]
        public readonly float $serving_grams,
        #[Field(minimum: 0)]
        public readonly float $protein,
        #[Field(minimum: 0)]
        public readonly float $fat,
        #[Field(minimum: 0)]
        public readonly float $carbs,
        #[Field(minimum: 0)]
        public readonly float $fiber,
        #[Field(maxLength: 255)]
        public readonly ?string $brand = null,
        #[Field(description: '1 to 14 digits')]
        public readonly ?string $barcode = null,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
        ];
    }
}
