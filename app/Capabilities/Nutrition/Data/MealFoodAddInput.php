<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** meal_food_add: the Pantry's basket or a scanned meal joins the meal (MEAL-002, FOOD-028, FOOD-045, RCP-001). */
final class MealFoodAddInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        /** @var list<MealFoodAddEntry> */
        #[Field(items: MealFoodAddEntry::class, minItems: 1)]
        public readonly array $entries,
    ) {}

    public static function rules(): array
    {
        return [
            'entries.*.id' => ['ulid'],
        ];
    }
}
