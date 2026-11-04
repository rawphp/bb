<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** meal_food_add_show: the Pantry's basket as meal_food_add would take it, to show what Add makes of it (FOOD-030). */
final class MealFoodAddShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        /** @var list<MealFoodAddEntry> */
        #[Field(items: MealFoodAddEntry::class, minItems: 1, maxItems: 50)]
        public readonly array $entries,
    ) {}

    public static function rules(): array
    {
        return MealFoodAddInput::rules();
    }
}
