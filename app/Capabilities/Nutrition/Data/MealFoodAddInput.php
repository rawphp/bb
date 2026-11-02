<?php

namespace App\Capabilities\Nutrition\Data;

use Closure;
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
            // Exactly one of food_id and recipe_id. A closure, since prohibits does not
            // resolve a * in the field it names.
            'entries.*' => [function (string $attribute, mixed $entry, Closure $fail): void {
                $food = is_array($entry) && ($entry['food_id'] ?? null) !== null;
                $recipe = is_array($entry) && ($entry['recipe_id'] ?? null) !== null;

                if ($food === $recipe) {
                    $fail('Each entry names a food_id or a recipe_id, not both.');
                }
            }],
        ];
    }
}
