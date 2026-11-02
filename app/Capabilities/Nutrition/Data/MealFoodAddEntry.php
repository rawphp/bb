<?php

namespace App\Capabilities\Nutrition\Data;

use App\Domain\MealFit\Weighing;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A pick joining the meal: a food or a recipe serving. Grams lock it at that amount (a scanned food, FOOD-045); without them it is fitted (MEAL-002). */
final class MealFoodAddEntry extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The new entry\'s ULID (CAP-019)')]
        public readonly string $id,
        public readonly ?string $food_id = null,
        public readonly ?string $recipe_id = null,
        #[Field(enum: [Weighing::Raw->value, Weighing::Cooked->value])]
        public readonly string $weighing = Weighing::Raw->value,
        #[Field(minimum: 0.1)]
        public readonly ?float $grams = null,
    ) {}
}
