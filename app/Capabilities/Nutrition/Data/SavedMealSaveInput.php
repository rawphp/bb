<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** saved_meal_save: save a meal's foods, or a scanned meal's matched foods, as a saved meal (MEAL-070, FOOD-046). */
final class SavedMealSaveInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The saved meal\'s ULID (CAP-019)')]
        public readonly string $id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $name,
        #[Field(description: 'Save this meal\'s foods')]
        public readonly ?string $meal_id = null,
        /** @var list<SavedMealFood> */
        #[Field(items: SavedMealFood::class, description: 'Or save these foods, locked')]
        public readonly array $foods = [],
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
        ];
    }
}
